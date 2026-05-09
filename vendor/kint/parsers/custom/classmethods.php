<?php

class Kint_Parsers_ClassMethods extends kintParser
{
	private static $cache = array();

	private static function _renderType( $type )
	{
		if ( ! $type ) {
			return '';
		}

		if ( class_exists( 'ReflectionUnionType' ) && $type instanceof ReflectionUnionType ) {
			$types = array();
			foreach ( $type->getTypes() as $innerType ) {
				$types[] = self::_renderType( $innerType );
			}
			return implode( '|', $types );
		}

		if ( class_exists( 'ReflectionIntersectionType' ) && $type instanceof ReflectionIntersectionType ) {
			$types = array();
			foreach ( $type->getTypes() as $innerType ) {
				$types[] = self::_renderType( $innerType );
			}
			return implode( '&', $types );
		}

		if ( class_exists( 'ReflectionNamedType' ) && $type instanceof ReflectionNamedType ) {
			$name = $type->getName();
			if ( $type->allowsNull() && $name !== 'mixed' && $name !== 'null' ) {
				$name = '?' . $name;
			}
			return $name;
		}

		return (string) $type;
	}

	private static function _renderDefaultValue( ReflectionParameter $param )
	{
		try {
			if ( method_exists( $param, 'isDefaultValueConstant' ) && $param->isDefaultValueConstant() ) {
				return $param->getDefaultValueConstantName();
			}

			return var_export( $param->getDefaultValue(), true );
		} catch ( ReflectionException $e ) {
			return '*UNAVAILABLE*';
		}
	}

	protected function _parse( &$variable )
	{
		if ( !KINT_PHP53 || !is_object( $variable ) ) return false;

		$className = get_class( $variable );

		# assuming class definition will not change inside one request
		if ( !isset( self::$cache[ $className ] ) ) {
			$reflection = new ReflectionClass( $variable );

			$public = $private = $protected = array();

			// Class methods
			foreach ( $reflection->getMethods() as $method ) {
				$params = array();

				// Access type
				$access = implode( ' ', Reflection::getModifierNames( $method->getModifiers() ) );

				// Method parameters
				foreach ( $method->getParameters() as $param ) {
					$paramString = '';

					if ( method_exists( $param, 'getType' ) ) {
						$type = self::_renderType( $param->getType() );
						if ( $type !== '' ) {
							$paramString .= $type . ' ';
						}
					}

					$paramString .= ( $param->isPassedByReference() ? '&' : '' )
						. ( method_exists( $param, 'isVariadic' ) && $param->isVariadic() ? '...' : '' )
						. '$' . $param->getName();

					if ( $param->isDefaultValueAvailable() ) {
						$paramString .= ' = ' . self::_renderDefaultValue( $param );
					}

					$params[] = $paramString;
				}

				$output = new kintVariableData;
				$docBlock = false;

				// Simple DocBlock parser, look for @return
				if ( ( $docBlock = $method->getDocComment() ) ) {
					$matches = array();
					if ( preg_match_all( '/@(\w+)\s+(.*)\r?\n/m', $docBlock, $matches ) ) {
						$lines = array_combine( $matches[1], $matches[2] );
						if ( isset( $lines['return'] ) ) {
							$output->operator = '->';
							# since we're outputting code, assumption that the string is utf8 is most likely correct
							# and saves resources
							$output->type = self::escape( $lines['return'], 'UTF-8' );
						}
					}
				}

				$output->name   = ( $method->returnsReference() ? '&' : '' ) . $method->getName() . '('
					. implode( ', ', $params ) . ')';
				$output->access = $access;

				if ( is_string( $docBlock ) ) {
					$lines = array();
					foreach ( explode( "\n", $docBlock ) as $line ) {
						$line = trim( $line );

						if ( in_array( $line, array( '/**', '/*', '*/' ) ) ) {
							continue;
						} elseif ( strpos( $line, '*' ) === 0 ) {
							$line = substr( $line, 1 );
						}

						$lines[] = self::escape( trim( $line ), 'UTF-8' );
					}

					$output->extendedValue = implode( "\n", $lines ) . "\n\n";
				}

				$declaringClass     = $method->getDeclaringClass();
				$declaringClassName = $declaringClass->getName();

				if ( $declaringClassName !== $className ) {
					$output->extendedValue .= "<small>Inherited from <i>{$declaringClassName}</i></small>\n";
				}

				$fileName = Kint::shortenPath( $method->getFileName() ) . ':' . $method->getStartLine();
				$output->extendedValue .= "<small>Defined in {$fileName}</small>";

				$sortName = $access . $method->getName();

				if ( $method->isPrivate() ) {
					$private[ $sortName ] = $output;
				} elseif ( $method->isProtected() ) {
					$protected[ $sortName ] = $output;
				} else {
					$public[ $sortName ] = $output;
				}
			}

			if ( !$private && !$protected && !$public ) {
				self::$cache[ $className ] = false;
			}

			ksort( $public );
			ksort( $protected );
			ksort( $private );

			self::$cache[ $className ] = $public + $protected + $private;
		}

		if ( count( self::$cache[ $className ] ) === 0 ) {
			return false;
		}

		$this->value = self::$cache[ $className ];
		$this->type  = 'Available methods';
		$this->size  = count( self::$cache[ $className ] );
	}
}

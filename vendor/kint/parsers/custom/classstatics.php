<?php

class Kint_Parsers_ClassStatics extends kintParser
{
	protected function _parse( & $variable )
	{
		if ( !KINT_PHP53 || !is_object( $variable ) ) return false;

		$extendedValue = array();

		$reflection = new ReflectionClass( $variable );
		// first show static values
		foreach ( $reflection->getProperties( ReflectionProperty::IS_STATIC ) as $property ) {
			if ( $property->isPrivate() ) {
				if ( PHP_VERSION_ID < 80100 && !method_exists( $property, 'setAccessible' ) ) {
					break;
				}
				if ( PHP_VERSION_ID < 80100 ) {
					$property->setAccessible( true );
				}
				$access = "private";
			} elseif ( $property->isProtected() ) {
				if ( PHP_VERSION_ID < 80100 ) {
					$property->setAccessible( true );
				}
				$access = "protected";
			} else {
				$access = 'public';
			}

			if ( method_exists( $property, 'isInitialized' ) && ! $property->isInitialized() ) {
				$output         = new kintVariableData;
				$output->type   = '*UNINITIALIZED*';
				$output->name   = '$' . $property->getName();
				$output->access = $access;
				$output->operator = '::';
				$extendedValue[] = $output;
				continue;
			} else {
				try {
					$_ = $property->getValue();
				} catch ( ReflectionException $e ) {
					$_ = '*UNAVAILABLE*';
				}
			}

			$output = kintParser::factory( $_, '$' . $property->getName() );

			$output->access   = $access;
			$output->operator = '::';
			$extendedValue[]  = $output;
		}

		foreach ( $reflection->getConstants() as $constant => $val ) {
			$output = kintParser::factory( $val, $constant );

			$output->access   = 'constant';
			$output->operator = '::';
			$extendedValue[]  = $output;
		}

		if ( empty( $extendedValue ) ) return false;

		$this->value = $extendedValue;
		$this->type  = 'Static class properties';
		$this->size  = count( $extendedValue );
	}
}

<?php
namespace FluidTYPO3\Flux\Utility;
/***************************************************************
 *  Copyright notice
 *
 *  (c) 2014 Claus Due <claus@namelesscoder.net>
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TYPO3\CMS\Extbase\Reflection\ClassReflection;
use TYPO3\CMS\Extbase\Reflection\ObjectAccess;
use TYPO3\CMS\Fluid\Core\Parser\TemplateParser;

/**
 * Annotation Utility
 *
 * @package Flux
 * @subpackage Utility
 */
class AnnotationUtility {

	/**
	 * @var array
	 */
	private static $cache = array(
		'reflections' => array(),
		'annotations' => array()
	);

	/**
	 * @param string $className
	 * @param string $annotationName
	 * @param string|boolean $propertyName
	 * @return array|boolean
	 */
	public static function getAnnotationValueFromClass($className, $annotationName, $propertyName = NULL) {
		$reflection = new ClassReflection($className);
		$sample = new $className();
		$annotations = array();
		if (NULL === $propertyName) {
			if (FALSE === $reflection->isTaggedWith($annotationName)) {
				return FALSE;
			}
			$annotations = $reflection->getTagValues($annotationName);
		} elseif (FALSE === $propertyName) {
			$properties = self::getGettablePropertyNames($sample);
			foreach ($properties as $reflectedPropertyName) {
				if (FALSE === property_exists($className, $reflectedPropertyName)) {
					continue;
				}
				$propertyAnnotationValues = self::getPropertyAnnotations($reflection, $reflectedPropertyName, $annotationName);
				if (NULL !== $propertyAnnotationValues) {
					$annotations[$reflectedPropertyName] = $propertyAnnotationValues;
				}
			}
		} else {
			$annotations = self::getPropertyAnnotations($reflection, $propertyName, $annotationName);
		}
		$annotations = self::parseAnnotation($annotations);
		if (NULL !== $propertyName && TRUE === isset($annotations[$propertyName])) {
			return $annotations[$propertyName];
		}
		return $annotations;
	}

    /**
     * Reimplementation of ObjectAccess::getGettablePropertyNames($object) without alphabetical sorting of output array
     *
     * Reasoning: When annotations are used to create a Flux form the creator wants some measure of control of the
     * order of the generated items, sorting those alphabetically completely denies that. Without that sorting the
     * reflection API orders the items in order of appearance inside the model class source code.
     *
     * @param object $object Object to receive property names for
     *
     * @throws \InvalidArgumentException
     * @return array Array of all gettable property names
     */
    protected static function getGettablePropertyNames($object) {
        if (!is_object($object)) {
            throw new \InvalidArgumentException('$object must be an object, ' . gettype($object) . ' given.', 1237301369);
        }
        if ($object instanceof \stdClass) {
            $declaredPropertyNames = array_keys(get_object_vars($object));
        } else {
            $declaredPropertyNames = array_keys(get_class_vars(get_class($object)));
        }
        foreach (get_class_methods($object) as $methodName) {
            if (is_callable(array($object, $methodName))) {
                if (substr($methodName, 0, 2) === 'is') {
                    $declaredPropertyNames[] = lcfirst(substr($methodName, 2));
                }
                if (substr($methodName, 0, 3) === 'get') {
                    $declaredPropertyNames[] = lcfirst(substr($methodName, 3));
                }
            }
        }
        $propertyNames = array_unique($declaredPropertyNames);
        //sort($propertyNames);
        return $propertyNames;
    }

	/**
	 * @param ClassReflection $reflection
	 * @param string $propertyName
	 * @param string $annotationName
	 * @return array
	 */
	protected static function getPropertyAnnotations(ClassReflection $reflection, $propertyName, $annotationName) {
		$reflectedProperty = $reflection->getProperty($propertyName);
		if (TRUE === $reflectedProperty->isTaggedWith($annotationName)) {
			return $reflectedProperty->getTagValues($annotationName);
		}
		return NULL;
	}

	/**
	 * @param string $argumentsAsString
	 * @return string
	 */
	protected static function parseAnnotationArguments($argumentsAsString) {
		$pattern = TemplateParser::$SPLIT_PATTERN_SHORTHANDSYNTAX_ARRAY_PARTS;
		$matches = array();
		preg_match_all($pattern, $argumentsAsString, $matches, PREG_SET_ORDER);
		$arguments = array();
		foreach ($matches as $match) {
			$name = $match['Key'];
			if (TRUE === isset($match['Subarray']) && 0 < strlen($match['Subarray'])) {
				$arguments[$name] = self::parseAnnotationArguments($match['Subarray']);
			} elseif (TRUE === isset($match['Number'])) {
				if (TRUE === ctype_digit($match['Number'])) {
					$arguments[$name] = intval($match['Number']);
				} elseif (FALSE !== strpos($match['Number'], '.')) {
					$arguments[$name] = floatval($match['Number']);
				}
			} elseif (TRUE === isset($match['QuotedString'])) {
				$arguments[$name] = trim($match['QuotedString'], '\'');
			}
		}
		return $arguments;
	}

	/**
	 * @param mixed $annotation
	 * @return string
	 */
	public static function parseAnnotation($annotation) {
		if (TRUE === is_array($annotation)) {
			if (TRUE === empty($annotation)) {
				return TRUE;
			} elseif (TRUE === isset($annotation[0]) && 1 === count($annotation)) {
				return self::parseAnnotation(array_pop($annotation));
			}
			return array_map(array(self, 'parseAnnotation'), $annotation);
		}
		$pattern = TemplateParser::$SPLIT_PATTERN_SHORTHANDSYNTAX_VIEWHELPER;
		$annotation = trim($annotation);
		if (TRUE === empty($annotation)) {
			// simple indication that annotation does exist but has no attributes.
			return TRUE;
		}
		if (FALSE === strpos($annotation, '(') && FALSE === strpos($annotation, ')')) {
			$annotation .= '()';
		}
		if (0 !== strpos($annotation, '{')) {
			$annotation = '{flux:' . $annotation . '}';
		}
		$matches = array();
		preg_match_all($pattern, $annotation, $matches, PREG_SET_ORDER);
		$structure = array(
			'type' => $matches[0]['MethodIdentifier'],
			'config' => self::parseAnnotationArguments($matches['0']['ViewHelperArguments'])
		);
		return $structure;
	}

}

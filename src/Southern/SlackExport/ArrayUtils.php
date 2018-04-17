<?php

namespace Southern\SlackExport;

/**
 * various array functions
 */
class ArrayUtils
{
    /**
     * Applies a callback to array elements.
     * Does the same as array_map, but does not generate warnings in case of exceptions inside callbacks
     * @param callable $callback
     * @param array $array
     * @return array
     */
    public static function map($callback, $array)
    {
        $result = array();
        foreach ($array as $key => $value) {
            $result[$key] = $callback($value);
        }
        return $result;
    }

    /**
     * Remove item(s) from array (by value)
     * @param array $array
     * @param mixed $removeValue - if array, every value from array will be removed
     * @param bool $strict - whether to compare values strictly
     */
    public static function remove(&$array, $removeValue, $strict = false)
    {
        $removeMultiple = is_array($removeValue);
        foreach ($array as $key => $value) {
            if ($removeMultiple) {
                $isDeleting = in_array($value, $removeValue, $strict);
            } else {
                if ($strict) {
                    $isDeleting = ($removeValue === $value);
                } else {
                    $isDeleting = ($removeValue == $value);
                }
            }
            if ($isDeleting) {
                unset($array[$key]);
            }
        }
    }

    /**
     * Improved version of array_combine. Allows different number of items in source arrays. Allows omitting "values".
     * @param array $keys
     * @param array $values - if omitted, keys are combined with itself, making array with values identical to keys
     * @return array
     */
    public static function combine($keys, $values = null)
    {
        if (count($keys) == 0 && count($values) == 0) {
            return array();
        }

        if ($values === null) {
            $values = $keys;
        }

        if (count($keys) > count($values)) {
            for ($i = count($values); $i < count($keys); $i++) {
                $values[] = null;
            }
        } elseif (count($keys) < count($values)) {
            $values = array_slice($values, 0, count($keys));
        }
        return array_combine($keys, $values);
    }

    /**
     * Sorts a rows array by their column with name $columnName
     *  "row" is an associative array
     *  "column" is an element of a row.
     *
     * @param array $rows - array of rows to sort
     * @param string $columnName - column name to sort by
     * @param bool $preserveKeys - whether to maintain index association
     */
    public static function sortByColumn(&$rows, $columnName, $preserveKeys = false)
    {
        $sortFunc = $preserveKeys ? 'uasort' : 'usort';
        $sortFunc($rows,
                function($a, $b) use ($columnName) {
            return ($a[$columnName] < $b[$columnName]) ? -1 : (($a[$columnName] > $b[$columnName]) ? 1 : 0);
        });
    }

    /**
     * Sorts an objects array by their field $fieldName
     * @param array $objects - array of objects to sort
     * @param string $fieldName - field name to sort by
     * @param bool $preserveKeys - whether to maintain index association
     */
    public static function sortByField(&$objects, $fieldName, $preserveKeys = false)
    {
        $sortFunc = $preserveKeys ? 'uasort' : 'usort';
        $sortFunc($objects,
                function($a, $b) use ($fieldName) {
            return ($a->$fieldName < $b->$fieldName) ? -1 : (($a->$fieldName > $b->$fieldName) ? 1 : 0);
        });
    }

    /**
     * Sorts an objects array by their property obtained calling $methodName
     * @param array $objects - array of objects to sort
     * @param string $methodName - method to call to get a property to sort by
     * @param bool $preserveKeys - whether to maintain index association
     */
    public static function sortByMethod(&$objects, $methodName, $preserveKeys = false)
    {
        $sortFunc = $preserveKeys ? 'uasort' : 'usort';
        $sortFunc($objects,
                function($a, $b) use ($methodName) {
            return ($a->$methodName() < $b->$methodName()) ? -1 : (($a->$methodName() > $b->$methodName()) ? 1 : 0);
        });
    }

    /**
     * Extracts a column $columnName value from each row of rows array, and returns the sequence of column values
     * @param array $rows array of rows
     * @param string $columnName
     * @return array
     */
    public static function extractColumn($rows, $columnName)
    {
        return array_map(
			function ($v) use($columnName) {return $v[$columnName];},
			$rows
		);
    }

    /**
     * Extracts a "column" (sequence of $fieldName value from each object of array), and returns the sequence of field values
     * @param array $objects array of objects
     * @param string $fieldName
     * @return array
     */
    public static function extractFieldSequence($objects, $fieldName)
    {
        return array_map(
			function ($v) use($fieldName) {return $v->$fieldName;},
			$objects
		);
    }

    /**
     * Returns an array containing results of calling $methodName on $objects elements.
     * @param array $objects array of objects
     * @param string $methodName
     * @return array
     */
    public static function mapMethod($objects, $methodName)
    {
        return array_map(
			function ($v) use($methodName) {return $v->$methodName();},
			$objects
		);
    }

    public static function pairImplode($array, $keyValueGlue = '=', $pairsGlue = ',')
    {
        $parts = array();
        foreach ($array as $key => $value) {
            $parts[] = $key . $keyValueGlue . $value;
        }
        return implode($pairsGlue, $parts);
    }

    /**
     * Similar to array_map but allows your callback to return both new key and new value
     * @param callable $callback - function($key, $value) { ...; return array($newKey, $newValue); }
     * @param array $array
     * @return array
     */
    public static function mapWithKeys($callback, $array)
    {
        $results = array();
        foreach ($array as $key => $value) {
            list($newKey, $newValue) = $callback($key, $value);
            $results[$newKey] = $newValue;
        }
        return $results;
    }

	public static function indexByMethod($array, $methodName = 'getId')
    {
		$results = array();
		foreach ($array as $item) {
			$id = $item->$methodName();
			$results[$id] = $item;
		}
		return $results;
	}

	public static function indexByField($array, $fieldName = 'id')
    {
		$results = array();
		foreach ($array as $item) {
			$id = $item->$fieldName;
			$results[$id] = $item;
		}
		return $results;
	}

	public static function indexByColumn($array, $columnName = 'id')
    {
		$results = array();
		foreach ($array as $item) {
			$id = $item[$columnName];
			$results[$id] = $item;
		}
		return $results;
	}

    /**
     * Returns a part of $sourceArray containing only $keys
     * @param array $sourceArray
     * @param array $keys
     * @param bool $nullSubstitute - whether to substitute missing fields as null
     * @return array
     */
    public static function subarray($sourceArray, $keys, $nullSubstitute=false)
    {
        $result = array();
        foreach($keys as $key)
        {
            if (array_key_exists($key, $sourceArray)) {
                $result[$key] = $sourceArray[$key];
            } elseif ($nullSubstitute) {
                $result[$key] = null;
            }
        }
        return $result;
    }

    /**
     * Converts two-dimensional array (rows array) to one-dimensional, using one column as keys and another as values.
     * @param array $array two-dimensional array
     * @param string $keyColumn -
     * @param string $valueColumn
     * @return array
     */
	public static function flatten($array, $keyColumn, $valueColumn) {
		$results = array();
		foreach ($array as $item) {
            $results[$item[$keyColumn]] = $item[$valueColumn];
		}
		return $results;
	}

    /**
     * Converts array so that it contains only utf-8 strings. Invalid characters are replaced with "?"
     * @param array $array
     * @return array
     */
    public static function toUtf8($array)
    {
        array_walk_recursive($array, function (&$item) {
            if (is_string($item)) {
                $item = mb_convert_encoding($item, "UTF-8", "UTF-8");
            }
        });
        return $array;
    }

}

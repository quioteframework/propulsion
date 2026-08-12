
/**
 * Runs the aggregate update on each related <?php echo $relationName ?> found by
 * findRelated<?php echo $relationName ?>s(), then forgets them.
 *
 * @param ?PropulsionPDO $con A connection object
 *
 * @return void
 */
protected function updateRelated<?php echo $relationName ?>s($con)
{
	foreach ($this-><?php echo $variableName ?>s ?? array() as $<?php echo $variableName ?>) {
		$<?php echo $variableName ?>-><?php echo $updateMethodName ?>($con);
	}
	// Back to the property's own initial state. It used to be reset to array(),
	// which meant the declared collection type had to admit a plain array purely
	// to describe the emptied case -- and a foreach over it before
	// findRelated<?php echo $relationName ?>s() had run would have hit null anyway.
	$this-><?php echo $variableName ?>s = null;
}


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
	foreach ($this-><?php echo $variableName ?>s as $<?php echo $variableName ?>) {
		$<?php echo $variableName ?>-><?php echo $updateMethodName ?>($con);
	}
	$this-><?php echo $variableName ?>s = array();
}


/**
 * Removes the object from the database without archiving it.
 *
 * @param PropulsionPDO $con Optional connection object
 *
 * @return     <?php echo $objectClassname ?> The current object (for fluent API support)
 */
public function deleteWithoutArchive(?PropulsionPDO $con = null)
{
	$this->archiveOnDelete = false;
	return $this->delete($con);
}

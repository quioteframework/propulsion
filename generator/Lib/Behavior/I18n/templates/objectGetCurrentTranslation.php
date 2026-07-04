
/**
 * Returns the current translation
 *
 * @param     PropulsionPDO $con an optional connection object
 *
 * @return <?php echo $i18nTablePhpName ?>
 */
public function getCurrentTranslation(?PropulsionPDO $con = null)
{
	return $this->getTranslation($this->getLocale(), $con);
}

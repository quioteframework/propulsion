<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
 namespace Propulsion\Generator\Model;

use Propulsion\Generator\Exception\EngineException;

/**
 * Data about a validation rule used in an application.
 *
 * @author     Michael Aichler <aichler@mediacluster.de> (Propel)
 * @author     John McNally <jmcnally@collab.net> (Intake)
 * @version    $Revision$
 */
class Rule extends XMLElement
{

	private ?string $name = null;
	private ?string $value = null;
	private ?string $message = null;
	private ?Validator $validator = null;
	private ?string $classname = null;

	/**
	 * Sets up the Rule object based on the attributes that were passed to loadFromXML().
	 * @see        parent::loadFromXML()
	 */
	protected function setupObject(): void
	{
		$this->name = $this->getStringAttribute("name");
		$this->value = $this->getStringAttribute("value");
		$this->classname = $this->getStringAttribute("class");

		/*
		* Set some default values if they are not specified.
		* This is escpecially useful for maxLength; the size
		* is already known by the column and this way it is
		* not necessary to manage the same size two times.
		*
		* Currently there is only one such supported default:
		*   - maxLength value = column max length
		*   (this default cannot be easily set at runtime w/o changing
		*   design of class system in undesired ways)
		*/
		if ($this->value === null && $this->name === 'maxLength') {
			$size = $this->requireValidator()->getColumn()?->getSize();
			if (is_int($size) || is_string($size)) {
				$this->value = (string) $size;
			}
		}

		$this->message = $this->getStringAttribute("message");
	}

	/**
	 * Sets the owning validator for this rule.
	 * @param      Validator $validator
	 * @see        Validator::addRule()
	 */
	public function setValidator(Validator $validator): void
	{
		$this->validator = $validator;
	}

	/**
	 * Gets the owning validator for this rule.
	 * @return     Validator|null
	 */
	public function getValidator()
	{
		return $this->validator;
	}

	/**
	 * Gets the owning validator for this rule, or throw if it hasn't been set
	 * yet. Validator::addRule() always calls setValidator() unconditionally
	 * before loadFromXML(), so every real (post-attach) call site can assume this.
	 *
	 * @throws EngineException
	 */
	public function requireValidator(): Validator
	{
		if ($this->validator === null) {
			throw new EngineException('This Rule has not been attached to a Validator.');
		}
		return $this->validator;
	}

	/**
	 * Sets the dot-path name of class to use for rule.
	 * If no class is specified in XML, then a classname will
	 * be built based on the 'name' attrib.
	 * @param      string|null $classname dot-path classname (e.g. myapp.propel.MyValidator)
	 */
	public function setClass($classname): void
	{
		$this->classname = $classname;
	}

	/**
	 * Gets the dot-path name of class to use for rule.
	 * If no class was specified, this method will build a default classname
	 * based on the 'name' attribute.  E.g. 'maxLength' -> 'propel.validator.MaxLengthValidator'
	 * @return     string|null dot-path classname (e.g. myapp.propel.MyValidator)
	 */
	public function getClass()
	{
		if ($this->classname === null && $this->name !== null) {
			return "propel.validator." . ucfirst($this->name) . "Validator";
		}
		return $this->classname;
	}

	/**
	 * Sets the name of the validator for this rule.
	 * This name is used to build the classname if none was specified.
	 * @param      string|null $name Validator name for this rule (e.g. "maxLength", "required").
	 * @see        getClass()
	 */
	public function setName($name): void
	{
		$this->name = $name;
	}

	/**
	 * Gets the name of the validator for this rule.
	 * @return     string|null Validator name for this rule (e.g. "maxLength", "required").
	 */
	public function getName()
	{
		return $this->name;
	}

	/**
	 * Sets the value parameter for this validator rule.
	 * Note: not all validators need a value parameter (e.g. 'required' validator
	 * does not).
	 * @param      string $value
	 */
	public function setValue($value): void
	{
		$this->value = $value;
	}

	/**
	 * Gets the value parameter for this validator rule.
	 * @return     string|null
	 */
	public function getValue()
	{
		return $this->value;
	}

	/**
	 * Sets the message that will be displayed to the user if validation fails.
	 * This message may be a Gettext msgid (if translation="gettext") or some other
	 * id for an alternative not-yet-supported translation system.  It may also
	 * be a simple, single-language string.
	 * @param      string $message
	 * @see        setTranslation()
	 */
	public function setMessage($message): void
	{
		$this->message = $message;
	}

	/**
	 * Gets the message that will be displayed to the user if validation fails.
	 * This message may be a Gettext msgid (if translation="gettext") or some other
	 * id for an alternative not-yet-supported translation system.  It may also
	 * be a simple, single-language string.
	 * @return     string
	 * @see        setTranslation()
	 */
	public function getMessage()
	{
		$message = str_replace('${value}', (string) $this->getValue(), $this->message ?? '');
		return $message;
	}

	/**
	 * @see        XMLElement::appendXml(\DOMNode)
	 */
	public function appendXml(\DOMNode $node): void
	{
		$doc = ($node instanceof \DOMDocument) ? $node : $node->ownerDocument;
		if ($doc === null) {
			throw new EngineException('Cannot append XML: given DOMNode has no owner document');
		}

		$ruleNode = $node->appendChild($doc->createElement('rule'));
		$ruleNode->setAttribute('name', $this->getName() ?? '');

		if (($value = $this->getValue()) !== null) {
			$ruleNode->setAttribute('value', $value);
		}

		if ($this->classname !== null) {
			$ruleNode->setAttribute('class', $this->getClass() ?? '');
		}

		$ruleNode->setAttribute('message', $this->getMessage());
	}

}

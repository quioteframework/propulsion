<?php

// save() is generated, so it lives on the trait now and parent:: can no longer
// reach it. The trait method is aliased and the call rewritten.
class Book extends BaseBook
{
    public function save($con = null)
    {
        return parent::save($con);
    }
}
-----
<?php

// save() is generated, so it lives on the trait now and parent:: can no longer
// reach it. The trait method is aliased and the call rewritten.
class Book extends \Propulsion\OM\BaseObject implements \Propulsion\OM\Persistent, \Propulsion\OM\Poolable, \Propulsion\OM\WritableModelInterface
{
    use BookGenerated {
        save as private generatedSave;
    }
    public function save($con = null)
    {
        return $this->generatedSave($con);
    }
}

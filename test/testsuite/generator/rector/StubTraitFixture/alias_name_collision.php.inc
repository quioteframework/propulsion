<?php

// The stub already declares generatedSave(), so the alias must not claim that
// name: an alias colliding with a method the class defines is silently useless,
// because the class's own method wins for that name.
class Book extends BaseBook
{
    public function save($con = null)
    {
        return parent::save($con);
    }
    public function generatedSave($con = null)
    {
        return 'mine';
    }
}
-----
<?php

// The stub already declares generatedSave(), so the alias must not claim that
// name: an alias colliding with a method the class defines is silently useless,
// because the class's own method wins for that name.
class Book extends \Propulsion\OM\BaseObject implements \Propulsion\OM\Persistent, \Propulsion\OM\Poolable, \Propulsion\OM\WritableModelInterface
{
    use BookGenerated {
        save as private generatedSave2;
    }
    public function save($con = null)
    {
        return $this->generatedSave2($con);
    }
    public function generatedSave($con = null)
    {
        return 'mine';
    }
}

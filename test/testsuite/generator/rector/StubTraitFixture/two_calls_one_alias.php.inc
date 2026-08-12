<?php

// Two parent:: calls to the same generated method share one alias, and the trait
// use gets one adaptation rather than two -- a duplicate alias for the same
// method is a compile error.
class Book extends BaseBook
{
    public function saveTwice($con = null)
    {
        parent::save($con);
        return parent::save($con);
    }
}
-----
<?php

// Two parent:: calls to the same generated method share one alias, and the trait
// use gets one adaptation rather than two -- a duplicate alias for the same
// method is a compile error.
class Book extends \Propulsion\OM\BaseObject implements \Propulsion\OM\Persistent, \Propulsion\OM\Poolable, \Propulsion\OM\WritableModelInterface
{
    use BookGenerated {
        save as private generatedSave;
    }
    public function saveTwice($con = null)
    {
        $this->generatedSave($con);
        return $this->generatedSave($con);
    }
}

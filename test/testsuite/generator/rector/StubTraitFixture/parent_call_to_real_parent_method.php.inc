<?php

// preSave() is declared on BaseObject, which is the stub's parent after the
// migration -- so parent::preSave() keeps working and must be left alone.
// Aliasing it would be a hard fatal: PHP rejects an alias for a method the trait
// does not define.
class Book extends BaseBook
{
    public function preSave(?\Propulsion\Connection\PropulsionPDO $con = null)
    {
        return parent::preSave($con);
    }
}
-----
<?php

// preSave() is declared on BaseObject, which is the stub's parent after the
// migration -- so parent::preSave() keeps working and must be left alone.
// Aliasing it would be a hard fatal: PHP rejects an alias for a method the trait
// does not define.
class Book extends \Propulsion\OM\BaseObject implements \Propulsion\OM\Persistent, \Propulsion\OM\Poolable, \Propulsion\OM\WritableModelInterface
{
    use BookGenerated;
    public function preSave(?\Propulsion\Connection\PropulsionPDO $con = null)
    {
        return parent::preSave($con);
    }
}

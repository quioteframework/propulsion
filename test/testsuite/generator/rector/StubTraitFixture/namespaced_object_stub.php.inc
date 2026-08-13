<?php

namespace App\Model;

use App\Model\OM\BaseBook;

class Book extends BaseBook
{
}
-----
<?php

namespace App\Model;

use App\Model\OM\BaseBook;

class Book extends \Propulsion\OM\BaseObject implements \Propulsion\OM\Persistent, \Propulsion\OM\Poolable, \Propulsion\OM\WritableModelInterface
{
    use \App\Model\OM\BookGenerated;
}

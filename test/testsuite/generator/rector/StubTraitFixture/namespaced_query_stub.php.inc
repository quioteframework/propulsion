<?php

namespace App\Model;

use App\Model\OM\BaseBookQuery;

class BookQuery extends BaseBookQuery
{
}
-----
<?php

namespace App\Model;

use App\Model\OM\BaseBookQuery;

class BookQuery extends \Propulsion\Query\ModelCriteria
{
    use \App\Model\OM\BookQueryGenerated;
}

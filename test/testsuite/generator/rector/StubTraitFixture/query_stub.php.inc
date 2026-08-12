<?php

class BookQuery extends BaseBookQuery
{
}
-----
<?php

class BookQuery extends \Propulsion\Query\ModelCriteria
{
    use BookQueryGenerated;
}

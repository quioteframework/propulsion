<?php

class BookNode extends BaseBookNode
{
}
-----
<?php

class BookNode implements \IteratorAggregate
{
    use BookNodeGenerated;
}

<?php
declare(strict_types=1);

const CB_DEPARTMENTS = [
    'College of Accountancy'=>'COA — College of Accountancy',
    'College of Education'=>'COE — College of Education',
    'College of Business Administration and Entrepreneurship'=>'CBAE — College of Business Administration and Entrepreneurship',
    'College of Computer Studies'=>'CCS — College of Computer Studies',
];

function cbDepartment(mixed $value): string {
    if(!is_string($value)||!isset(CB_DEPARTMENTS[$value]))throw new InvalidArgumentException('Choose one of the four school departments.');
    return $value;
}

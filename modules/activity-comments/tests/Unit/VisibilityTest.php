<?php

use Liberu\Foundation\ActivityComments\Support\Visibility;

it('enforces visibility for each combination of membership staff and ownership', function (bool $member, bool $staff, bool $owner, array $expected) {
    foreach (Visibility::cases() as $index => $visibility) {
        expect($visibility->visible($member, $staff, $owner))
            ->toBe($expected[$index], $visibility->value);
    }
})->with([
    'visitor' => [false, false, false, [true, false, false, false]],
    'owner outside the membership' => [false, false, true, [true, false, false, true]],
    'staff outside the membership' => [false, true, false, [true, true, true, true]],
    'staff owner outside the membership' => [false, true, true, [true, true, true, true]],
    'member' => [true, false, false, [true, true, false, false]],
    'member owner' => [true, false, true, [true, true, false, true]],
    'member staff' => [true, true, false, [true, true, true, true]],
    'member staff owner' => [true, true, true, [true, true, true, true]],
]);

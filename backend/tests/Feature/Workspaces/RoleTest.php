<?php

use App\Enums\WorkspaceRole;
use App\Models\Workspace;

it('reports the role a user has in a workspace', function () {
    $owner = verifiedUser();
    $other = verifiedUser(['email' => 'other@example.com']);
    $ws = Workspace::create(['name' => 'Acme', 'slug' => 'acme', 'owner_id' => $owner->id]);
    $ws->members()->attach($owner->id, ['role' => WorkspaceRole::Owner->value]);

    expect($owner->roleIn($ws))->toBe(WorkspaceRole::Owner)->and($other->roleIn($ws))->toBeNull();
});

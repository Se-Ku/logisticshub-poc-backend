<?php

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;

class UserInput
{
    #[Groups(['user:write'])]
    public ?string $email = null;

    #[Groups(['user:write'])]
    public ?string $password = null;

    #[Groups(['user:write'])]
    public array $roles = [];
}

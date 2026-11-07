<?php

namespace App\Enums;

enum CodePurpose: string
{
    case VerifyEmail = 'verify';
    case Login = 'login';
    case Confirm = 'confirm'; // confirms a destructive action for an account without a password
}

<?php

namespace App\Enums;

enum ReturnStaffRole: string
{
    case None = 'none';
    case Staff = 'staff';       // İade personeli — 1. onay / işleme
    case Manager = 'manager';   // Yönetici — 2. onay (para iadesini tetikler)

    public function canStaffApprove(): bool
    {
        return $this === self::Staff || $this === self::Manager;
    }

    public function canManagerApprove(): bool
    {
        return $this === self::Manager;
    }
}

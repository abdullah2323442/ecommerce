<?php

namespace App\Enums;

/**
 * Every top-level area of the platform. A user can only reach a section the
 * AccountType grants (spec ch 05). Denied sections behave as if they do not exist.
 */
enum Section: string
{
    case Public = 'public';
    case Paikari = 'paikari';
    case Wholesale = 'wholesale';
    case Food = 'food';
    case Gaming = 'gaming';
    case Help = 'help';
    case Mpo = 'mpo';
    case Craftsman = 'craftsman';
    case AdminPanel = 'admin_panel';
}

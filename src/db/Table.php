<?php

namespace justinholtweb\bexy\db;

/**
 * Bexy's database tables.
 */
abstract class Table
{
    public const DOCUMENTS = '{{%bexy_documents}}';
    public const PAYMENTS = '{{%bexy_payments}}';
    public const CONTACTS = '{{%bexy_contacts}}';
    public const TOKENS = '{{%bexy_tokens}}';
    public const LOG = '{{%bexy_log}}';
}

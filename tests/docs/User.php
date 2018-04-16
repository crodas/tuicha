<?php

class User {
    use Tuicha\Document;

    /** @Required @Index(sparse=true) */
    public $name;

    /** @Validate (is_email) @Unique */
    public $email;

    /**
     * @Validate(is_integer, @between(0, 99), "CustomValidator::isInt")
     */
    public $age;

    /** @reference(with=['email']) */
    public $ref;

    public function t()
    {
        static $val;
        if (!$val) {
            $val = uniqid();
        }
        return $val;
    }
}

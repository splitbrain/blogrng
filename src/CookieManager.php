<?php

namespace splitbrain\blogrng;

class CookieManager
{
    const COOKIENAME = 'blogrngseen';
    const MAX = 100;

    /** @var array list of seen posts */
    protected $seen = [];

    public function __construct()
    {
        if (isset($_COOKIE[self::COOKIENAME])) {
            $this->seen = array_map('intval', explode(',', $_COOKIE[self::COOKIENAME]));
        }
    }

    /**
     * Add a new post ID to the seen posts cookie
     *
     * @param int $id
     * @return void
     */
    public function addSeenPostID($id)
    {
        array_unshift($this->seen, $id);
        if (count($this->seen) > self::MAX) {
            $this->seen = array_slice($this->seen, 0, self::MAX);
        }

        $expire = time() + 60 * 60 * 24 * 365;
        setcookie(self::COOKIENAME, join(',', $this->seen), $expire);
    }

    /**
     * Get all the seen posts, newest first
     *
     * @return array
     */
    public function getSeenPostIDs()
    {
        return $this->seen;
    }
}

<?php
// $wpdb, recording rather than running SQL. Scenarios that exercise the real
// store, the installer or uninstall.php read back the statements it received.
// Answers come from agm_wpdb_answer(); the only statements it acts on are the
// ones uninstall.php needs to be checkable: dropping a table and deleting
// options by LIKE pattern.

class Agm_Fake_WPDB {
    public $prefix = 'wp_';
    public $base_prefix = 'wp_';
    public $options = 'wp_options';
    public $usermeta = 'wp_usermeta';
    public $postmeta = 'wp_postmeta';
    public $posts = 'wp_posts';
    public $last_error = '';
    public $rows_affected = 0;
    public $insert_id = 0;
    public $queries = array();
    public $tables = array();
    public $answers = array();

    public function set_blog($id) {
        $this->prefix = $id > 1 ? 'wp_' . (int) $id . '_' : 'wp_';
        $this->options = $this->prefix . 'options';
        $this->postmeta = $this->prefix . 'postmeta';
        $this->posts = $this->prefix . 'posts';
    }
    public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci'; }
    public function esc_like($text) { return addcslashes((string) $text, '_%\\'); }
    public function prepare($query, ...$args) {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;
        return preg_replace_callback('/%%|%[sdfi]/', function ($m) use (&$i, $args) {
            if ($m[0] === '%%') {
                return '%';
            }
            $value = isset($args[$i]) ? $args[$i] : null;
            $i++;
            switch ($m[0]) {
                case '%d':
                    return (string) (int) $value;
                case '%f':
                    return (string) (float) $value;
                case '%i':
                    // WordPress quotes every identifier; plain table names stay bare here so the
                    // fakes and scenarios match one spelling of each table, however it was written.
                    $name = str_replace('`', '', (string) $value);
                    return preg_match('/^\w+$/', $name) ? $name : '`' . $name . '`';
                default:
                    return "'" . addslashes((string) $value) . "'";
            }
        }, $query);
    }
    private function record($kind, $sql) {
        $sql = trim(preg_replace('/\s+/', ' ', (string) $sql));
        $this->queries[] = array('kind' => $kind, 'sql' => $sql);
        return $sql;
    }
    private function answer($sql, $default) {
        foreach ($this->answers as $pattern => $answer) {
            if (preg_match($pattern, $sql)) {
                return is_callable($answer) ? call_user_func($answer, $sql) : $answer;
            }
        }
        return $default;
    }
    public function query($sql) {
        $sql = $this->record('query', $sql);
        if (preg_match('/^DROP TABLE IF EXISTS `?(\w+)`?/i', $sql, $m)) {
            unset($this->tables[$m[1]]);
            return true;
        }
        if (preg_match('/^DELETE FROM `?' . preg_quote($this->options, '/') . '`? WHERE (.+)$/i', $sql, $m)) {
            preg_match_all("/option_name LIKE '((?:[^'\\\\]|\\\\.)*)'/i", $m[1], $likes);
            $deleted = 0;
            foreach (array_keys($GLOBALS['agm']['options']) as $name) {
                foreach ($likes[1] as $like) {
                    if (agm_sql_like($name, stripslashes($like))) {
                        unset($GLOBALS['agm']['options'][$name]);
                        $deleted++;
                        break;
                    }
                }
            }
            foreach (array_keys($GLOBALS['agm']['transients']) as $name) {
                foreach ($likes[1] as $like) {
                    if (agm_sql_like('_transient_' . $name, stripslashes($like))) {
                        unset($GLOBALS['agm']['transients'][$name]);
                        break;
                    }
                }
            }
            $this->rows_affected = $deleted;
            return $deleted;
        }
        $result = $this->answer($sql, 1);
        $this->rows_affected = is_int($result) ? $result : 1;
        return $result;
    }
    public function get_results($sql, $output = OBJECT) { return $this->answer($this->record('get_results', $sql), array()); }
    public function get_row($sql, $output = OBJECT, $y = 0) { return $this->answer($this->record('get_row', $sql), null); }
    public function get_col($sql, $x = 0) { return $this->answer($this->record('get_col', $sql), array()); }
    public function get_var($sql, $x = 0, $y = 0) {
        $sql = $this->record('get_var', $sql);
        if (preg_match("/^SHOW TABLES LIKE '((?:[^'\\\\]|\\\\.)*)'/i", $sql, $m)) {
            foreach (array_keys($this->tables) as $name) {
                if (agm_sql_like($name, stripslashes($m[1]))) {
                    return $name;
                }
            }
            return null;
        }
        return $this->answer($sql, null);
    }
    public function insert($table, $data, $format = null) { $this->record('insert', 'INSERT INTO ' . $table . ' ' . json_encode($data)); $this->insert_id++; return 1; }
    public function update($table, $data, $where, $format = null, $where_format = null) { $this->record('update', 'UPDATE ' . $table . ' ' . json_encode(array($data, $where))); return 1; }
    public function delete($table, $where, $where_format = null) { $this->record('delete', 'DELETE FROM ' . $table . ' ' . json_encode($where)); return 1; }

    /** Statements received, optionally only those matching a pattern. */
    public function statements($pattern = null) {
        return array_values(array_map(function ($q) { return $q['sql']; }, array_filter($this->queries, function ($q) use ($pattern) {
            return $pattern === null || preg_match($pattern, $q['sql']);
        })));
    }
}

/** MySQL LIKE, with \_ and \% as literals. */
function agm_sql_like($subject, $pattern) {
    $regex = '';
    $length = strlen($pattern);
    for ($i = 0; $i < $length; $i++) {
        $char = $pattern[$i];
        if ($char === '\\' && $i + 1 < $length) {
            $regex .= preg_quote($pattern[++$i], '/');
        } elseif ($char === '%') {
            $regex .= '.*';
        } elseif ($char === '_') {
            $regex .= '.';
        } else {
            $regex .= preg_quote($char, '/');
        }
    }
    return (bool) preg_match('/^' . $regex . '$/s', $subject);
}

$GLOBALS['wpdb'] = new Agm_Fake_WPDB();

/** dbDelta records each CREATE TABLE and remembers the table as existing, unless listed in $GLOBALS['agm']['dbdelta_skip']. */
function dbDelta($queries = '', $execute = true) {
    global $wpdb;
    foreach ((array) $queries as $sql) {
        foreach (preg_split('/;\s*(?=CREATE)/i', (string) $sql) as $statement) {
            if (preg_match('/CREATE TABLE `?(\w+)`?/i', $statement, $m)) {
                if (!empty($GLOBALS['agm']['dbdelta_skip']) && in_array($m[1], $GLOBALS['agm']['dbdelta_skip'], true)) {
                    continue;
                }
                $wpdb->tables[$m[1]] = $statement;
                $GLOBALS['agm']['dbdelta'][] = $statement;
            }
        }
    }
    return array();
}

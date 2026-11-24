<?php
// The plugin's store, in memory: the same methods as Agumail_Store, with the
// same meaning, over two arrays. Every write to the outbox or carts table is
// logged, which is how a scenario counts the writes a request made. The drain
// lock lives in the options array under its real name.
//
// agm_plugin_loaded() hands it to the plugin with agumail_set_store().

class Agm_Fake_Store {
    public $outbox = array();
    public $carts = array();
    private $next_id = 1;

    // ── Outbox ──

    public function enqueue($type, $key, $payload, $now) {
        foreach ($this->outbox as $id => $row) {
            if ($row['type'] === $type && $row['object_key'] === $key) {
                $this->outbox[$id] = array_merge($row, array(
                    'payload' => $payload, 'version' => $row['version'] + 1, 'status' => 'pending',
                    'available_at' => $now, 'updated_at' => $now,
                ));
                agm_record_write('outbox', $type . ':' . $key, 'update');
                return true;
            }
        }
        $id = $this->next_id++;
        $this->outbox[$id] = array(
            'id' => $id, 'type' => $type, 'object_key' => $key, 'payload' => $payload, 'version' => 1, 'attempts' => 0,
            'status' => 'pending', 'available_at' => $now, 'last_error' => null, 'created_at' => $now, 'updated_at' => $now,
        );
        agm_record_write('outbox', $type . ':' . $key, 'insert');
        return true;
    }
    public function find($type, $key) {
        foreach ($this->outbox as $row) {
            if ($row['type'] === $type && $row['object_key'] === $key) {
                return agumail_outbox_row_types($row);
            }
        }
        return null;
    }
    public function get($id) { return isset($this->outbox[$id]) ? agumail_outbox_row_types($this->outbox[$id]) : null; }
    public function due($type, $limit, $now, $after_id = 0) {
        $rows = array();
        foreach ($this->outbox as $id => $row) {
            if ($row['status'] === 'pending' && $row['type'] === $type && $row['available_at'] <= $now && $id > $after_id) {
                $rows[] = agumail_outbox_row_types($row);
            }
            if (count($rows) >= $limit) {
                break;
            }
        }
        return $rows;
    }
    public function complete($id, $version) {
        if (isset($this->outbox[$id]) && $this->outbox[$id]['version'] === (int) $version) {
            unset($this->outbox[$id]);
            agm_record_write('outbox', (string) $id, 'delete');
            return true;
        }
        if (isset($this->outbox[$id])) {
            $this->outbox[$id]['attempts'] = 0;
            $this->outbox[$id]['last_error'] = null;
            agm_record_write('outbox', (string) $id, 'reset');
        }
        return false;
    }
    public function drop($id, $version) {
        if (isset($this->outbox[$id]) && $this->outbox[$id]['version'] === (int) $version) {
            unset($this->outbox[$id]);
            agm_record_write('outbox', (string) $id, 'delete');
            return true;
        }
        return false;
    }
    public function park($id, $version, $error, $now) {
        if (!isset($this->outbox[$id]) || $this->outbox[$id]['version'] !== (int) $version) {
            return false;
        }
        $this->outbox[$id]['status'] = 'failed';
        $this->outbox[$id]['last_error'] = $error;
        $this->outbox[$id]['updated_at'] = $now;
        agm_record_write('outbox', (string) $id, 'park');
        return true;
    }
    public function defer($id, $attempts, $available_at, $error, $failed, $now) {
        if (!isset($this->outbox[$id])) {
            return false;
        }
        $this->outbox[$id] = array_merge($this->outbox[$id], array(
            'attempts' => (int) $attempts, 'available_at' => $available_at, 'last_error' => $error,
            'status' => $failed ? 'failed' : 'pending', 'updated_at' => $now,
        ));
        agm_record_write('outbox', (string) $id, 'defer');
        return true;
    }
    public function retry($id, $now) {
        if (!isset($this->outbox[$id])) {
            return false;
        }
        $this->outbox[$id] = array_merge($this->outbox[$id], array('status' => 'pending', 'attempts' => 0, 'available_at' => $now, 'last_error' => null, 'updated_at' => $now));
        agm_record_write('outbox', (string) $id, 'retry');
        return true;
    }
    public function retry_failed($now) {
        $count = 0;
        foreach ($this->outbox as $id => $row) {
            if ($row['status'] === 'failed') {
                $this->retry($id, $now);
                $count++;
            }
        }
        return $count;
    }
    public function discard($id, $version = null) {
        if (!isset($this->outbox[$id]) || $this->outbox[$id]['status'] !== 'failed'
            || ($version !== null && $this->outbox[$id]['version'] !== (int) $version)) {
            return false;
        }
        unset($this->outbox[$id]);
        agm_record_write('outbox', (string) $id, 'discard');
        return true;
    }
    public function discard_failed() {
        $count = 0;
        foreach ($this->outbox as $id => $row) {
            if ($row['status'] === 'failed') {
                $this->discard($id);
                $count++;
            }
        }
        return $count;
    }
    public function discard_pending(array $types, $before = null) {
        $count = 0;
        foreach ($this->outbox as $id => $row) {
            if ($row['status'] === 'pending' && in_array($row['type'], $types, true) && ($before === null || $row['updated_at'] < $before)) {
                unset($this->outbox[$id]);
                agm_record_write('outbox', (string) $id, 'discard');
                $count++;
            }
        }
        return $count;
    }
    public function clear() {
        $count = count($this->outbox);
        $this->outbox = array();
        agm_record_write('outbox', '*', 'clear');
        return $count;
    }
    public function stats($now) {
        $stats = array('pending' => 0, 'failed' => 0, 'due' => 0, 'oldest_pending_at' => null, 'by_type' => array());
        foreach ($this->outbox as $row) {
            $status = $row['status'] === 'failed' ? 'failed' : 'pending';
            $stats[$status]++;
            if (!isset($stats['by_type'][$row['type']])) {
                $stats['by_type'][$row['type']] = array('pending' => 0, 'failed' => 0);
            }
            $stats['by_type'][$row['type']][$status]++;
            if ($status === 'pending') {
                if ($row['available_at'] <= $now) {
                    $stats['due']++;
                }
                if ($stats['oldest_pending_at'] === null || $row['created_at'] < $stats['oldest_pending_at']) {
                    $stats['oldest_pending_at'] = $row['created_at'];
                }
            }
        }
        return $stats;
    }
    public function failed($limit, $offset) {
        $rows = array_values(array_filter($this->outbox, function ($row) { return $row['status'] === 'failed'; }));
        usort($rows, function ($a, $b) { return $a['updated_at'] === $b['updated_at'] ? $b['id'] - $a['id'] : strcmp($b['updated_at'], $a['updated_at']); });
        return array_map('agumail_outbox_row_types', array_slice($rows, $offset, $limit));
    }

    // ── Carts ──

    public function cart_get($id) { return isset($this->carts[$id]) ? agumail_cart_row_types($this->carts[$id]) : null; }
    public function cart_find_by_session($session_key, array $statuses) {
        $found = null;
        foreach ($this->carts as $row) {
            if ($row['session_key'] === $session_key && (!$statuses || in_array($row['status'], $statuses, true))
                && ($found === null || $row['updated_at'] >= $found['updated_at'])) {
                $found = $row;
            }
        }
        return $found ? agumail_cart_row_types($found) : null;
    }
    public function carts_by_email($email, array $statuses, $limit) {
        $rows = array_values(array_filter($this->carts, function ($row) use ($email, $statuses) {
            return $row['email'] === $email && (!$statuses || in_array($row['status'], $statuses, true));
        }));
        return array_map('agumail_cart_row_types', array_slice($rows, 0, $limit));
    }
    public function has_work($now) {
        foreach ($this->outbox as $row) {
            if ($row['status'] === 'pending' && $row['available_at'] <= $now) {
                return true;
            }
        }
        foreach ($this->carts as $row) {
            if ($row['dirty'] === 1) {
                return true;
            }
        }
        return false;
    }
    public function cart_save($id, array $fields, $now, array $insert_only = array()) {
        $fields = agumail_cart_known_columns($fields);
        $insert_only = agumail_cart_known_columns($insert_only);
        $fields = array_map(function ($value) { return $value === null ? null : (string) $value; }, $fields);
        if (isset($this->carts[$id])) {
            $this->carts[$id] = array_merge($this->carts[$id], $fields, array('version' => $this->carts[$id]['version'] + 1, 'dirty' => 1, 'updated_at' => $now));
            agm_record_write('carts', $id, 'update');
            return true;
        }
        $this->carts[$id] = array_merge(array('id' => $id, 'token' => '', 'session_key' => '', 'user_id' => null, 'email' => null,
            'lines' => '[]', 'total' => '0', 'currency' => '', 'status' => 'open', 'order_id' => null, 'ip_hash' => ''), $insert_only, $fields,
            array('version' => 1, 'queued_version' => 0, 'dirty' => 1, 'created_at' => $now, 'updated_at' => $now));
        agm_record_write('carts', $id, 'insert');
        return true;
    }
    public function cart_update($id, array $fields, $bump, $now) {
        if (!isset($this->carts[$id])) {
            return false;
        }
        $fields = agumail_cart_known_columns($fields);
        $fields = array_map(function ($value) { return $value === null ? null : (string) $value; }, $fields);
        $this->carts[$id] = array_merge($this->carts[$id], $fields, array('updated_at' => $now));
        if ($bump) {
            $this->carts[$id]['version']++;
            $this->carts[$id]['dirty'] = 1;
        }
        agm_record_write('carts', $id, 'update');
        return true;
    }
    public function carts_dirty($limit) {
        $rows = array_values(array_filter($this->carts, function ($row) { return $row['dirty'] === 1; }));
        return array_map('agumail_cart_row_types', array_slice($rows, 0, $limit));
    }
    public function carts_clear_dirty() {
        $count = 0;
        foreach ($this->carts as $id => $row) {
            if ($row['dirty'] === 1) {
                $this->carts[$id]['dirty'] = 0;
                $count++;
            }
        }
        if ($count) {
            agm_record_write('carts', '*', 'clean');
        }
        return $count;
    }
    public function cart_mark_queued($id, $version) {
        if (!isset($this->carts[$id]) || $this->carts[$id]['queued_version'] >= $version) {
            return false;
        }
        $this->carts[$id]['queued_version'] = (int) $version;
        $this->carts[$id]['dirty'] = $this->carts[$id]['version'] > (int) $version ? 1 : 0;
        agm_record_write('carts', $id, 'queued');
        return true;
    }
    public function carts_count_by_ip($ip_hash, $since) {
        return count(array_filter($this->carts, function ($row) use ($ip_hash, $since) {
            return $row['ip_hash'] === $ip_hash && $row['created_at'] >= $since;
        }));
    }
    public function cart_delete($id) {
        if (!isset($this->carts[$id])) {
            return false;
        }
        unset($this->carts[$id]);
        agm_record_write('carts', 'delete', (string) $id);
        return true;
    }
    public function carts_purge(array $statuses, $before) {
        $count = 0;
        foreach ($this->carts as $id => $row) {
            if ($row['updated_at'] < $before && (!$statuses || in_array($row['status'], $statuses, true))) {
                unset($this->carts[$id]);
                $count++;
            }
        }
        if ($count) {
            agm_record_write('carts', 'purge', (string) $count);
        }
        return $count;
    }

    // ── Locks ──

    public function acquire_lock($name, $ttl, $now) {
        $existing = isset($GLOBALS['agm']['options'][$name]) ? (string) $GLOBALS['agm']['options'][$name] : '';
        if ($existing !== '') {
            $parts = explode('|', $existing);
            if ((int) end($parts) >= (int) $now) {
                return false;
            }
        }
        $token = wp_generate_uuid4();
        $GLOBALS['agm']['options'][$name] = $token . '|' . ((int) $now + (int) $ttl);
        agm_record_write('lock', $name, 'acquire');
        return $token;
    }
    public function release_lock($name, $token) {
        $existing = isset($GLOBALS['agm']['options'][$name]) ? (string) $GLOBALS['agm']['options'][$name] : '';
        if (strpos($existing, $token . '|') === 0) {
            unset($GLOBALS['agm']['options'][$name]);
            agm_record_write('lock', $name, 'release');
        }
    }
}

/** The fake store the plugin is using. */
function agm_store() {
    return agumail_store();
}

/** Outbox rows, decoded, optionally of one type. */
function agm_outbox($type = null) {
    return array_values(array_map('agumail_outbox_row_types', array_filter(agm_store()->outbox, function ($row) use ($type) {
        return $type === null || $row['type'] === $type;
    })));
}

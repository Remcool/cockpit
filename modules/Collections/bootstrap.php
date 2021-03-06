<?php

$this->module("collections")->extend([

    'createCollection' => function($name, $data = []) {

        if (!trim($name)) {
            return false;
        }

        $configpath = $this->app->path('#storage:').'/collections';

        if (!$this->app->path('#storage:collections')) {

            if (!$this->app->helper('fs')->mkdir($configpath)) {
                return false;
            }
        }

        if ($this->exists($name)) {
            return false;
        }

        $time = time();

        $collection = array_replace_recursive([
            'name'      => $name,
            'label'     => $name,
            '_id'       => uniqid($name),
            'fields'    => [],
            'sortable'  => false,
            'in_menu'   => false,
            '_created'  => $time,
            '_modified' => $time
        ], $data);

        $export = var_export($collection, true);

        if (!$this->app->helper('fs')->write("#storage:collections/{$name}.collection.php", "<?php\n return {$export};")) {
            return false;
        }

        $this->app->trigger('collections.createcollection', [$collection]);

        return $collection;
    },

    'updateCollection' => function($name, $data) {

        $metapath = $this->app->path("#storage:collections/{$name}.collection.php");

        if (!$metapath) {
            return false;
        }

        $data['_modified'] = time();

        $collection  = include($metapath);
        $collection  = array_merge($collection, $data);
        $export      = var_export($collection, true);

        if (!$this->app->helper('fs')->write($metapath, "<?php\n return {$export};")) {
            return false;
        }

        $this->app->trigger('collections.updatecollection', [$collection]);
        $this->app->trigger("collections.updatecollection.{$name}", [$collection]);

        return $collection;
    },

    'saveCollection' => function($name, $data) {

        if (!trim($name)) {
            return false;
        }

        return isset($data['_id']) ? $this->updateCollection($name, $data) : $this->createCollection($name, $data);
    },

    'removeCollection' => function($name) {

        if ($collection = $this->collection($name)) {

            $this->app->helper("fs")->delete("#storage:collections/{$name}.collection.php");
            $this->app->storage->dropCollection("collections/{$collection}");

            $this->app->trigger('collections.removecollection', [$name]);
            $this->app->trigger("collections.removecollection.{$name}", [$name]);

            return true;
        }

        return false;
    },

    'collections' => function($extended = false) {

        $stores = [];

        foreach($this->app->helper("fs")->ls('*.collection.php', '#storage:collections') as $path) {

            $store = include($path->getPathName());

            if ($extended) {
                $store['itemsCount'] = $this->count($store['name']);
            }

            $stores[$store['name']] = $store;
        }

        return $stores;
    },

    'exists' => function($name) {
        return $this->app->path("#storage:collections/{$name}.collection.php");
    },

    'collection' => function($name) {

        static $collections; // cache

        if (is_null($collections)) {
            $collections = [];
        }

        if (!is_string($name)) {
            return false;
        }

        if (!isset($collections[$name])) {

            $collections[$name] = false;

            if ($path = $this->exists($name)) {
                $collections[$name] = include($path);
            }
        }

        return $collections[$name];
    },

    'entries' => function($name) use($app) {

        $_collection = $this->collection($name);

        if (!$_collection) return false;

        $collection = $_collection['_id'];

        return $this->app->storage->getCollection("collections/{$collection}");
    },

    'find' => function($collection, $options = []) {

        $_collection = $this->collection($collection);

        if (!$_collection) return false;

        $name       = $collection;
        $collection = $_collection['_id'];

        // sort by custom order if collection is sortable
        if (isset($_collection['sortable']) && $_collection['sortable'] && !isset($options['sort'])) {
            $options['sort'] = ['_order' => 1];
        }

        $this->app->trigger('collections.find.before', [$name, &$options, false]);
        $this->app->trigger("collections.find.before.{$name}", [$name, &$options, false]);

        $entries = (array)$this->app->storage->find("collections/{$collection}", $options);

        if (isset($options['populate']) && $options['populate']) {
            $entries = $this->_populate($_collection, $entries, is_numeric($options['populate']) ? intval($options['populate']) : false);
        }

        $this->app->trigger('collections.find.after', [$name, &$entries, false]);
        $this->app->trigger("collections.find.after.{$name}", [$name, &$entries, false]);

        return $entries;
    },

    'findOne' => function($collection, $criteria = [], $projection = null, $populate = false) {

        $_collection = $this->collection($collection);

        if (!$_collection) return false;

        $name       = $collection;
        $collection = $_collection['_id'];

        $this->app->trigger('collections.find.before', [$name, &$criteria, true]);
        $this->app->trigger("collections.find.before.{$name}", [$name, &$criteria, true]);

        $entry = $this->app->storage->findOne("collections/{$collection}", $criteria, $projection);

        if ($entry && $populate) {
           $entry = $this->_populate($_collection, [$entry], is_numeric($populate) ? intval($populate) : false);
           $entry = $entry[0];
        }

        $this->app->trigger('collections.find.after', [$name, &$entry, true]);
        $this->app->trigger("collections.find.after.{$name}", [$name, &$entry, true]);

        return $entry;
    },

    'save' => function($collection, $data) {

        $_collection = $this->collection($collection);

        if (!$_collection) return false;

        $name       = $collection;
        $collection = $_collection['_id'];
        $data       = isset($data[0]) ? $data : [$data];
        $return     = [];
        $modified   = time();

        foreach($data as $entry) {

            $isUpdate = isset($entry['_id']);

            $entry['_modified'] = $modified;

            if (isset($_collection['fields'])) {

                foreach($_collection['fields'] as $field) {

                    // skip missing fields on update
                    if (!isset($entry[$field['name']]) && $isUpdate) {
                        continue;
                    }

                    if (!isset($entry[$field['name']])) {
                        $value = isset($field['default']) ? $field['default'] : '';
                    } else {
                        $value = $entry[$field['name']];
                    }

                    switch($field['type']) {

                        case 'string':
                        case 'text':
                            $value = (string)$value;
                            break;

                        case 'boolean':

                            if ($value === 'true' || $value === 'false') {
                                $value = $value === 'true' ? true:false;
                            } else {
                                $value = $value ? true:false;
                            }

                            break;

                        case 'number':
                            $value = is_numeric($value) ? $value:0;
                            break;

                        case 'url':
                            $value = filter_var($value, FILTER_VALIDATE_URL, FILTER_FLAG_SCHEME_REQUIRED) ? $value:null;
                            break;

                        case 'email':
                            $value = filter_var($value, FILTER_VALIDATE_EMAIL) ? $value:null;
                            break;

                        case 'password':

                            if ($value) {

                                $value = $this->app->hash($value);
                            }

                            break;
                    }

                    if ($isUpdate && $field['type'] == 'password' && !$value && isset($entry[$field['name']])) {
                        unset($entry[$field['name']]);
                    } else {
                        $entry[$field['name']] = $value;
                    }

                }
            }

            if (!$isUpdate) {
                $entry["_created"] = $entry["_modified"];
            }

            $this->app->trigger('collections.save.before', [$name, &$entry, $isUpdate]);
            $this->app->trigger("collections.save.before.{$name}", [$name, &$entry, $isUpdate]);

            $ret = $this->app->storage->save("collections/{$collection}", $entry);

            $this->app->trigger('collections.save.after', [$name, &$entry, $isUpdate]);
            $this->app->trigger("collections.save.after.{$name}", [$name, &$entry, $isUpdate]);

            $return[] = $ret ? $entry : false;
        }

        return count($return) == 1 ? $return[0] : $return;
    },

    'remove' => function($collection, $criteria) {

        $_collection = $this->collection($collection);

        if (!$_collection) return false;

        $name       = $collection;
        $collection = $_collection['_id'];

        $this->app->trigger('collections.remove.before', [$name, &$criteria]);
        $this->app->trigger("collections.remove.before.{$name}", [$name, &$criteria]);

        $result = $this->app->storage->remove("collections/{$collection}", $criteria);

        $this->app->trigger('collections.remove.after', [$name, $result]);
        $this->app->trigger("collections.remove.after.{$name}", [$name, $result]);

        return $result;
    },

    'count' => function($collection, $criteria = []) {

        $_collection = $this->collection($collection);

        if (!$_collection) return false;

        $collection = $_collection['_id'];

        return $this->app->storage->count("collections/{$collection}", $criteria);
    },

    '_populate' => function($collection, $entries, $deep = false, $_deeplevel = -1) {

        static $cache;

        if (null === $cache) {
            $cache = [];
        }

        // a maximum of recursive level
        if (is_numeric($deep) && $_deeplevel == $deep) {
            return $entries;
        }

        if (!$collection || !count($entries)) {
            return $entries;
        }

        $hasOne  = [];
        $hasMany = [];

        foreach($collection['fields'] as &$field) {

            if ($field['type'] == 'collectionlink' && isset($field['options']['link']) && $field['options']['link']) {

                if (isset($field['options']['multiple']) && $field['options']['multiple']) {
                    $hasMany[$field['name']] = $field['options']['link'];
                } else {
                    $hasOne[$field['name']] = $field['options']['link'];
                }

                if (!isset($cache[$field['options']['link']])) {
                    $cache[$field['options']['link']] = [];
                }
            }
        }

        foreach ($entries as &$entry) {

            // resolve hasOne
            foreach ($hasOne as $_field => $_collection) {

                if (isset($entry[$_field]['_id'])) {

                    if (!is_string($entry[$_field]['_id'])) {
                        $entry[$_field]['_id'] = (string)$entry[$_field]['_id'];
                    }

                    if (!isset($cache[$_collection])) {
                        $cache[$_collection] = [];
                    }

                    if (!isset($cache[$_collection][$entry[$_field]['_id']])) {
                        $cache[$_collection][$entry[$_field]['_id']] = $this->findOne($_collection, ['_id' => $entry[$_field]['_id']]);
                    }

                    $entry[$_field] = $cache[$_collection][$entry[$_field]['_id']];

                    if ($entry[$_field] && $deep) {
                        $_entry = $this->_populate($this->collection($_collection), [$entry[$_field]], $deep, ($_deeplevel+1));
                        $entry[$_field] = $_entry[0];
                    }
                }
            }

            // resolve hasMany
            foreach ($hasMany as $_field => $_collection) {

                if (isset($entry[$_field]) && $entry[$_field] && is_array($entry[$_field])) {

                    $links = [];

                    foreach ($entry[$_field] as $data) {

                        if (!isset($data['_id'])) continue;

                        if (!is_string($data['_id'])) {
                            $data['_id'] = (string)$data['_id'];
                        }

                        if (!isset($cache[$_collection])) {
                            $cache[$_collection] = [];
                        }

                        if (!isset($cache[$_collection][$data['_id']])) {
                            $cache[$_collection][$data['_id']] = $this->findOne($_collection, ['_id' => $data['_id']]);
                        }

                        if ($cache[$_collection][$data['_id']]) {
                            $links[] = $cache[$_collection][$data['_id']];
                        }
                    }

                    if ($deep && count($links)) {
                        $links = $this->_populate($this->collection($_collection), $links, $deep, ($_deeplevel+1));
                    }

                    $entry[$_field] = $links;
                }
            }
        }

        return $entries;
    }
]);


// REST
if (COCKPIT_REST) {

    $app->on('cockpit.rest.init', function($routes) {
        $routes['collections'] = 'Collections\\Controller\\RestApi';
    });
}


// ADMIN
if (COCKPIT_ADMIN && !COCKPIT_REST) {

    include_once(__DIR__.'/admin.php');
}

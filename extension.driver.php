<?php

    class Extension_CacheableDatasource extends Extension
    {
        private $_sectionsToFlush = null;

        public function install()
        {
            if (!General::realiseDirectory(CACHE . '/cacheabledatasource', Symphony::Configuration()->get('write_mode', 'directory'))) {
                throw new Exception(__('Cacheable Datasource was not installed: cache directory could not be created at %s.', array('<code>/manifest/cache/cacheabledatasource</code>')));
            }
            return true;
        }

        public function uninstall()
        {
            if (is_dir(CACHE . '/cacheabledatasource')) {
                rmdir(CACHE . '/cacheabledatasource');
            }
        }

        public function getSubscribedDelegates()
        {
            return array(
                array(
                    'page'        => '/frontend/',
                    'delegate'    => 'DataSourcePreExecute',
                    'callback'    => 'dataSourcePreExecute'
                ),
                array(
                    'page'        => '/blueprints/datasources/',
                    'delegate'    => 'DatasourcePreCreate',
                    'callback'    => 'dataSourceSave'
                ),
                array(
                    'page'        => '/blueprints/datasources/',
                    'delegate'    => 'DatasourcePreEdit',
                    'callback'    => 'dataSourceSave'
                ),
                array(
                    'page' => '/backend/',
                    'delegate' => 'InitaliseAdminPageHead',
                    'callback' => 'initaliseAdminPageHead'
                    ),
                array(
                    'page'      => '/publish/new/',
                    'delegate'  => 'EntryPostCreate',
                    'callback'  => 'flushCache'
                ),
                array(
                    'page'      => '/publish/edit/',
                    'delegate'  => 'EntryPostEdit',
                    'callback'  => 'flushCache'
                ),
                array(
                    'page'      => '/publish/',
                    'delegate'  => 'EntryPreDelete',
                    'callback'  => 'flushCache'
                ),
                array(
                    'page'      => '/publish/',
                    'delegate'  => 'EntriesPostOrder',
                    'callback'  => 'flushCache'
                ),
                array(
                    'page'      => '/frontend/',
                    'delegate'  => 'EventFinalSaveFilter',
                    'callback'  => 'eventFinalSaveFilter'
                ),
                array(
                    'page' => '/blueprints/events/new/',
                    'delegate' => 'AppendEventFilter',
                    'callback' => 'appendEventFilter'
                ),
                array(
                    'page' => '/blueprints/events/edit/',
                    'delegate' => 'AppendEventFilter',
                    'callback' => 'appendEventFilter'
                ),
            );
        }

        public function flushCache($context) 
        {

            $flushSections = $this->__fetchSectionsFromContext($context);

            $cacheDir = CACHE . '/cacheabledatasource/';

            try {
                foreach(DatasourceManager::listAll() as $ds) {
                    if(!in_array($ds['source'], $flushSections)) continue;

                    $cache = glob($cacheDir . $ds['handle'] . '_*.xml');
                    if(empty($cache)) continue;
                    foreach($cache as $file) {
                        unlink($file);
                    }
                }

            } catch(Exception $e){
                Symphony::Log()->writeToLog(date('d.m.y H:i:s') . ' > CacheableDatasource: ' . $e->getMessage(), true);
            }
        }

        private function __fetchSectionsFromContext($context) 
        {

            if($context['delegate'] == 'EntryPreDelete' || $context['delegate'] == 'EntriesPostOrder') {
                $current_section_id = EntryManager::fetchEntrySectionID($context['entry_id'][0]);
            } else if($context['delegate'] == 'EventFinalSaveFilter') {
                $current_section_id = EntryManager::fetchEntrySectionID($context['entry']->get('id'));
            } else {
                $current_section_id = $context['section']->get('id');
            }

            $data = Symphony::Database()->fetch(
                sprintf('SELECT DISTINCT `child_section_id`, `parent_section_id` FROM `tbl_sections_association`
                    WHERE `parent_section_id` = %d OR `child_section_id` = %d',
                    $current_section_id,
                    $current_section_id
                )
            );

            $result = array($current_section_id);

            foreach ($data as $v) {
                array_push($result, $v['child_section_id']);
                array_push($result, $v['parent_section_id']);
            }

            return array_unique($result);
        }

        public function eventFinalSaveFilter(array $context)
        {
            if (!in_array('cacheable-datasource', $context['event']->eParamFILTERS)) {
                return;
            }

            $this->flushCache($context);
        }

        public function appendEventFilter(array $context)
        {
            $context['options'][] = array(
                'cacheable-datasource',
                is_array($context['selected']) ? in_array('cacheable-datasource', $context['selected']) : false,
                'Flush DS Cache'
            );
        }

        /**
         * `DatasourcePreCreate` delegate callback function
         * Checks whether a data source should be cached or not: builds a filename based on
         * the hashed object parameters. If a cache file exists and is not stale, the XML is
         * read from the file and returned. If the cache file does not exist, or exists but is
         * stale, the data source grab() is executed and the XML cached.
         *
         * @param mixed $context
         *  Delegate context including the data source object, output XML and param pool array
         */
        public function dataSourcePreExecute($context)
        {
            $ds = $context['datasource'];
            $param_pool = $context['param_pool'];

            // don't cache if no cache TTL is set at all
            if (!isset($ds->dsParamCACHE)) {
                return;
            }
            // don't cache when the TTL is zero
            if ((int)$ds->dsParamCACHE == 0) {
                return;
            }

            $filename = null;
            $file_age = 0;

            if ($this->__buildCacheFilename($ds, $filename, $file_age)) {

                // HACK: peek at the first line of XML to see if it's a serialised array
                // which contains cached output parameters

                $xml = file_get_contents($filename);

                // split XML into an array of each line
                $xml_lines = explode("\n", $xml);

                // output params are a serialised array on line 1
                $output_params = @unserialize(trim($xml_lines[0]));


                // there are cached output parameters
                if (is_array($output_params)) {

                    // remove line 1 and join XML into a string again
                    unset($xml_lines[0]);
                    $xml = implode("\n", $xml_lines);

                    // add cached output params back into the pool
                    foreach ($output_params as $key => $value) {
                        $param_pool[$key] = $value;
                    }
                }

                // set cache age in the XML result
                $xml = preg_replace('/cache-age="fresh"/', 'cache-age="'.$file_age.'s"', $xml);
            } else {

                // Backup the param pool
                $old_param_pool = $param_pool;

                // Fetch the contents
                $xml = $this->__executeDatasource($ds, $param_pool);

                // See what has been added to the param pool
                $new_params = array_diff_key($param_pool, $old_param_pool);

                // Create output params string (null or one line)
                $output_params = null;
                if (!empty($new_params)) {
                    $output_params = sprintf("%s\n", serialize($new_params));
                }

                // Add an attribute to preg_replace later
                $xml->setAttribute("cache-age", "fresh");

                // Write the cached XML to disk
                file_put_contents($filename, $output_params . $xml->generate(true, 1));
            }

            $context['xml'] = $xml;
            $context['param_pool'] = $param_pool;
        }

        /**
         * Serialises the data source object properties into a checksum hash to see
         * whether the data source is currently cached, and whether it has expired.
         * Returns boolean of whether the data source is stale or not.
         *
         * @param Datasource $datasource
         *  The current data source object
         * @param string $filename
         *  Cache filename, passed by reference
         * @param int $file_age
         *  Cache file age (in seconds), passed by reference
         */
        private function __buildCacheFilename($datasource, &$filename, &$file_age)
        {
            $filename = null;

            // Checks if cacheabledatasource directory exists. If not, try to restore.
            if (!file_exists(CACHE . '/cacheabledatasource')) {
                if (!General::realiseDirectory(CACHE . '/cacheabledatasource', Symphony::Configuration()->get('write_mode', 'directory'))) {
                    throw new Exception(__('Cacheable Datasource: Cache directory could not be restored at %s.', array('<code>/manifest/cache/cacheabledatasource</code>')));
                }
            }

            // get resolved values of each public property of this DS
            // (sort, filters, included elements etc.)
            foreach (get_class_vars(get_class($datasource)) as $key => $value) {
                if (substr($key, 0, 2) == 'ds') {
                    $value = $datasource->{$key};
                    $filename .= $key . (is_array($value) ? http_build_query($value) : $value);
                }
            }

            $filename = sprintf(
                "%s/cache/cacheabledatasource/%s_%s.xml",
                MANIFEST,
                preg_replace("/^datasource/", '', get_class($datasource)),
                md5($filename)
            );

            if (!file_exists($filename)) {
                return false;
            }

            $file_age = (int)(floor(time() - filemtime($filename)));

            return ($file_age < ($datasource->dsParamCACHE));
        }

        /**
         * Executes a data source. Invalid XML is escaped (CDATA) but still
         * cached. Prevents persistent cached XML from breaking pages.
         *
         * @param Datasource $datasource
         *  The current data source object
         * @param int $file_age
         *  Cache file age (in seconds), passed by reference
         */
        private function __executeDatasource($datasource, &$param_pool=array())
        {
            $result = $datasource->grab($param_pool);
            $xml = is_object($result) ? $result->generate(true, 1) : $result;

            // Parse DS XML to check for errors. If contains malformed XML such as
            // an unescaped database error, the error is escaped in CDATA
            $doc = new DOMDocument('1.0', 'utf-8');

            libxml_use_internal_errors(true);
            $doc->loadXML($xml);
            $errors = libxml_get_errors();
            libxml_clear_errors();
            libxml_use_internal_errors(false);

            // No error, just return the result
            if (empty($errors)) {
                return $result;
            }

            // There's an error, so $doc will be empty
            // Use regex to get the root node
            // If something's wrong, just push back the broken XML
            if (!preg_match('/<([^ \/>]+)/', $xml, $matches)) {
                return $result;
            }

            $ret = new XMLElement($matches[1]);

            // Set the invalid flag
            $ret->setAttribute("xml-invalid", "true");

            $errornode = new XMLElement("errors");

            // Store the errors
            foreach ($errors as $error) {
                $item = new XMLElement("error", trim($error->message));
                $item->setAttribute('line', $error->line);
                $item->setAttribute('column', $error->column);
                $errornode->appendChild($item);
            }

            $ret->appendChild($errornode);

            // Return the XML
            $ret->appendChild(new XMLElement('broken-xml', "<![CDATA[" . $xml . "]]>"));

            return $ret;
        }

        /**
         * `DatasourcePreCreate` and `DatasourcePreEdit` delegates callback function
         * Adds the dsParamCACHE property to the data source class file.
         *
         * @param mixed $context
         *  Delegate context including string contents of the data souce PHP file
         */
        public function dataSourceSave($context)
        {
            $contents = $context['contents'];
            $cache = $_POST['fields']['cache'];

            if (!isset($cache)) {
                return;
            }

            $contents = preg_replace(
                "/<!-- VAR LIST -->/",
                "public \$dsParamCACHE = '$cache';\n\t\t<!-- VAR LIST -->",
                $contents
            );

            $context['contents'] = $contents;
        }

        /**
         * `InitaliseAdminPageHead` delegate callback function
         * Appends script assets and context to page head
         *
         * @param mixed $context
         *  Delegate context including page object
         */
        public function initaliseAdminPageHead($context)
        {
            $page = Administration::instance()->Page;
            if (!$page instanceof contentBlueprintsDatasources) {
                return;
            }

            $url_context = $page->getContext();
            if (!in_array($url_context[0], array('new', 'edit'))) {
                return;
            }

            $cache = 0;

            // if editing an existing data source, instantiate the DS object
            // to retrieve the dsParamCACHE property if it exists
            if ($url_context[0] == 'edit') {
                $ds = $url_context[1];
                $dsm = new DatasourceManager(Symphony::Engine());
                $datasource = $dsm->create($ds, null, false);
                $cache = $datasource->dsParamCACHE;
            }
            if (is_null($cache)) {
                $cache = 0;
            }

            Administration::instance()->Page->addElementToHead(
                new XMLElement(
                    'script',
                    "Symphony.Context.add('cacheabledatasource', " . json_encode(array(
                        'cache' => (isset($_POST['fields']['cache']) ? $_POST['fields']['cache'] : $cache)
                    )) . ");",
                    array('type' => 'text/javascript')
                ), time()
            );

            Administration::instance()->Page->addScriptToHead(
                URL . '/extensions/cacheabledatasource/assets/cacheabledatasource.blueprintsdatasources.js',
                time()
            );
        }
    }

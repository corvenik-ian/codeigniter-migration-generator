<?php


/**
 * CI Migrations Generator from existing database Library
 *
 * Create a base file for migrations to start off with;
 *
 * @author Ian Jiang
 * @author kinsay
 * @license Free to use and abuse
 * @version 0.1.0 Beta
 * @link https://github.com/corvenik-ian/codeigniter-migration-generator
 *
 * Description: Improvement version based on Ian Jiang work. Now the library supports Unique keys, non-primary and non-unique keys and foreign
 */

class Migration_lib
{
    /**
     * @var object $_ci codeigniter
     */
    private $_ci = NULL;

    /**
     * @var array collect used timestamp
     */
    private $_timestamp_set = array();

    /**
     * @var array migration table set
     */
    protected $migration_table_set = [];

    /**
     * @var array migration table set
     */
    protected $foreign_keys = [];

    /**
     * @var string path
     */
    protected $path = '';
    /**
     * @var string database name
     */
    private $_db_name = '';

    /**
     * @var bool 
     */
    private $file_per_table = FALSE; //usefull for the first migration

    /**
     * @var array skip table name set
     */
    protected $skip_tables = ['migrations'];

    /**
     * @var bool add view
     */
    protected $add_view = FALSE;



    /**
     * Migration_lib constructor.
     */
    public function __construct()
    {
        // Get Codeigniter Object
        if( ! isset($this->_ci))
        {
            $this->_ci =& get_instance();
        }

        $this->_ci->config->load('migration');

        $this->path = $this->_ci->config->item('migration_path');

        $this->_ci->load->database();

        // get database name
        $this->_db_name = $this->_ci->db->database;
    }

    /**
     * Generate Migrations Files
     *
     * @param string $tables
     *
     * @return boolean|string
     */
    public function generate($tables = '*')
    {
        // check tables not empty
        if(empty($tables))
        {
            echo 'InvalidParameter::tables';
            return FALSE;
        }

        //check forlder is writable
        if (!is_dir($this->path) OR !is_really_writable($this->path))
        {
            $msg = "Unable to write migration in folder: " . $this->path;
            echo $msg;
            return FALSE;
        }
        $this->getForeignKeys();
        $this->getTables($tables);

        // create migration file or override it.
        if( ! empty($this->migration_table_set))
        {
            if($this->file_per_table === TRUE)
            //Create as many files as tables exist
            {
                foreach ($this->migration_table_set as $table_name)
                {
                    $file_content = $this->get_file_content($table_name);

                    if(! empty($file_content))
                    {
                        $this->write_file($table_name, $file_content);
                        continue;
                    } 

                }
            }
            else
            // create a single file for the whole database
            {
                $file_content = $this->get_file_content_bulk();

                if(! empty($file_content))
                {
                    $this->write_file('base_'.$this->_db_name, $file_content);
                }  
            }

            echo "Create migration success!";
            return TRUE;
        }
        else
        {
            echo "Empty table set!";
            return FALSE;
        }
    }

    function getTables($tables)
    {
        if ($tables === '*')
        {
            $query = $this->_ci->db->query('SHOW FULL TABLES FROM ' . $this->_ci->db->protect_identifiers($this->_db_name));

            // collect tables of migration
            $migration_table_set = array();

            // confirm table num
            if ($query->num_rows() > 0)
            {
                $table_name_key = "Tables_in_{$this->_db_name}";

                foreach ($query->result_array() as $table_info)
                {
                    if (isset($table_info[$table_name_key]) && $table_info[$table_name_key] !== '')
                    {
                        $table_name = $table_info[$table_name_key];

                        // check if table in skip arrays, if so, go next
                        if (in_array($table_info[$table_name_key], $this->skip_tables))
                        {
                            continue;
                        }

                        // skip views
                        if (strtolower($table_info['Table_type']) == 'view')
                        {
                            continue;
                        }

                        $migration_table_set[] = $table_info["Tables_in_{$this->_db_name}"];
                    }
                }
            }

            if($this->_ci->db->dbprefix($this->_db_name) !== '')
            {
                array_walk($migration_table_set, [$this, '_remove_database_prefix']);
            }

            $this->migration_table_set = $migration_table_set;
        }
        else
        {
            $this->migration_table_set = is_array($tables) ? $tables : explode(',', $tables);
        }
    }

    function getForeignKeys()
    {
        $foreign_keys = array();

        $query = $this->_ci->db->query("SELECT kcu.referenced_table_schema, kcu.constraint_name, kcu.table_name, kcu.column_name, kcu.referenced_table_name, kcu.referenced_column_name,  kcu.POSITION_IN_UNIQUE_CONSTRAINT, 
                                        rc.update_rule, rc.delete_rule 
                                        FROM INFORMATION_SCHEMA.key_column_usage kcu
                                        JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc on kcu.constraint_name = rc.constraint_name
                                        WHERE kcu.referenced_table_schema = '$this->_db_name' 
                                        AND kcu.referenced_table_name IS NOT NULL 
                                        ORDER BY kcu.table_name, kcu.column_name");

        if ($query->num_rows() > 0)
        {
            foreach ($query->result_array() as $fk_info)
            {
                // check if table in skip arrays, if so, go next
                if (in_array($fk_info['table_name'], $this->skip_tables))
                {
                    continue;
                }

                $foreign_keys[$fk_info['table_name']][] = array('constraint_name' => $fk_info['constraint_name'],
                                                                'unique' => $fk_info['POSITION_IN_UNIQUE_CONSTRAINT'], 
                                                                'column' => $fk_info['column_name'], 
                                                                'ref_table' => $fk_info['referenced_table_name'], 
                                                                'ref_column' => $fk_info['referenced_column_name'],
                                                                'on_update' => $fk_info['update_rule'],
                                                                'on_delete' => $fk_info['delete_rule']);
            }
        }

        $this->foreign_keys = $foreign_keys;
    }

    /**
     * _remove_database_prefix
     *
     * @param string $table_name
     *
     * @return void
     */
    private function _remove_database_prefix(&$table_name)
    {
        // insensitive replace
        $table_name = str_ireplace($this->_ci->db->dbprefix, '', $table_name);
    }

    /**
     * get_file_content
     *
     * @param string $table_name table name
     *
     * @return string $file_content
     */
    public function get_file_content($table_name)
    {
        $file_content = '<?php ';
        $file_content .= 'defined(\'BASEPATH\') OR exit(\'No direct script access allowed\');' . "\n\n";
        $file_content .= "class Migration_create_{$table_name} extends CI_Migration" . "\n";
        $file_content .= '{' . "\n";
        $file_content .= $this->get_function_up_content($table_name);
        $file_content .= $this->get_function_down_content($table_name);
        $file_content .= "\n" . '}' . "\n";

        // replace tab into 4 space
        $file_content = str_replace("\t", '    ', $file_content);

        return $file_content;
    }

    public function get_file_content_bulk()
    {
        $file_content = '<?php ';
        $file_content .= 'defined(\'BASEPATH\') OR exit(\'No direct script access allowed\');' . "\n\n";
        $file_content .= "class Migration_create_{$table_name} extends CI_Migration" . "\n";
        $file_content .= '{' . "\n";
        $file_content .= $this->get_function_up_content_bulk();
        $file_content .= $this->get_function_down_content(null);
        $file_content .= "\n" . '}' . "\n";

        // replace tab into 4 space
        $file_content = str_replace("\t", '    ', $file_content);

        return $file_content;
    }


    /**
     * writeFile
     *
     * @param string $table_name   table name
     * @param string $file_content file content
     *
     * @return void
     */
    public function write_file($table_name, $file_content)
    {
        $file = $this->open_file($table_name);
        fwrite($file, $file_content);
        fclose($file);
    }

    /**
     * openFile
     *
     * @param string $table_name table name
     *
     * @return bool|resource
     */
    public function open_file($table_name)
    {
        $timestamp = $this->_get_timestamp($table_name);

        $file_path = $this->path . '/' . $timestamp .'_create_' . $table_name . '.php';

        // Open for reading and writing.
        // Place the file pointer at the beginning of the file and truncate the file to zero length.
        // If the file does not exist, attempt to create it.
        $file = fopen($file_path, 'w+');

        if ( ! $file)
        {
            return FALSE;
        }

        // add this timestamp to timestamp ser
        $this->_timestamp_set[] = $timestamp;

        return $file;
    }

    /**
     * _get_timestamp get
     *
     * @param $table_name
     * @return string
     */
    private function _get_timestamp($table_name)
    {
        // get timestamp
        $query = $this->_ci->db->query(' SHOW TABLE STATUS WHERE Name = \'' . $table_name .'\'');

        $engines = $query->row_array();

        $timestamp = date('YmdHis', strtotime($engines['Create_time']));

        while(in_array($timestamp, $this->_timestamp_set))
        {
            $timestamp += 1;
        }

        return $timestamp;
    }

    /**
     * Base on table name create migration up function
     *
     * @param $table_name
     *
     * @return string
     */
    public function get_function_up_content($table_name)
    {
        $str = "\n\t" . '/**' . "\n";
        $str .= "\t" . ' * up (create table)' . "\n";
        $str .= "\t" . ' *' . "\n";
        $str .= "\t" . ' * @return void' . "\n";
        $str .= "\t" . ' */' . "\n";
        $str .= "\t" . 'public function up()' . "\n";
        $str .= "\t" . '{' . "\n";

        $query = $this->_ci->db->query("SHOW FULL FIELDS FROM {$this->_ci->db->dbprefix($table_name)} FROM {$this->_db_name}");
        echo '<pre>'; print_r($query->result_array()); echo '</pre>'; 
        if ($query->result() === NULL)
        {
            return FALSE;
        }

        $columns = $query->result_array();//获取列数据

        $add_key_str = '';

        $add_fk_key_str = '';

        $add_field_str = "\t\t" . '$this->dbforge->add_field(array(' . "\n";

        foreach ($columns as $column)
        {
            // field name
            $add_field_str .= "\t\t\t'{$column['Field']}' => array(" . "\n";

            preg_match('/^(\w+)\(([\d]+(?:,[\d]+)*)\)/', $column['Type'], $match);

            if($match === [])
            {
                preg_match('/^(\w+)/', $column['Type'], $match);
            }

            $add_field_str .= "\t\t\t\t'type' => '" . strtoupper($match[1]) . "'," . "\n";

            if(isset($match[2]))
            {
                switch (strtoupper($match[1]))
                {
                    //type enum need extra handle
                    case 'ENUM':
                        $enum_constraint_str = str_replace(',', ', ', $match[2]);
                        $add_field_str .= "\t\t\t\t'constraint' => [" . $enum_constraint_str . "],\n";
                        break;
                    default:
                        $add_field_str .= "\t\t\t\t'constraint' => '" . strtoupper($match[2]) . "'," . "\n";
                        break;
                }
            }

            $add_field_str .= (strstr($column['Type'], 'unsigned')) ? "\t\t\t\t'unsigned' => TRUE," . "\n" : '';

            $add_field_str .= ((string) $column['Default'] !== '') ? "\t\t\t\t'default' => '" . $column['Default'] . "'," . "\n" : '';

            $add_field_str .= ((string) $column['Comment'] !== '') ? "\t\t\t\t'comment' => '" . str_replace("'", "\\'", $column['Comment']) . "',\n" : '';

            $add_field_str .= ($column['Null'] !== 'NO') ? "\t\t\t\t'null' => TRUE," . "\n" : '';

            $add_field_str .= ($column['Key'] == 'UNI') ? "\t\t\t\t'unique' => TRUE," . "\n" : '';

            $add_field_str .= "\t\t\t)," . "\n";

            if ($column['Key'] == 'PRI')
            {
                $add_key_str .= "\t\t" . '$this->dbforge->add_key("' . $column['Field'] . '", TRUE);' . "\n";
            } 
            else if ($column['Key'] == 'MUL')
            {
                $add_key_str .= "\t\t" . '$this->dbforge->add_key("' . $column['Field'] . '");' . "\n";
            }
        }

        $add_field_str .= "\t\t));" . "\n";

        $str .= "\n\t\t" . '// Add Fields.' . "\n";
        $str .= $add_field_str;    

        $str .= ($add_key_str !== '') ? "\n\t\t" . '// Add Primary Key.' . "\n" . $add_key_str : '';

        // create db

        $query = $this->_ci->db->query(' SHOW TABLE STATUS WHERE Name = \'' . $table_name . '\'');

        $engines = $query->row_array();

        $attributes_str = "\n\t\t" . '$attributes = array(' . "\n";;
        $attributes_str .= ((string) $engines['Engine'] !== '') ? "\t\t\t'ENGINE' => '" . $engines['Engine'] . "'," . "\n" : '';
        $attributes_str .= ((string) $engines['Comment'] !== '') ? "\t\t\t'COMMENT' => '\\'" . str_replace("'", "\\'", $engines['Comment']) . "'\\'',\n" : '';
        $attributes_str .= "\t\t" . ');' . "\n";

        $str .= "\n\t\t" . '// Table attributes.' . "\n";
        $str .= $attributes_str;

        $str .= "\n\t\t" . '// Create Table ' . $table_name . "\n";
        $str .= "\t\t" . '$this->dbforge->create_table("' . $table_name . '", TRUE, $attributes);' . "\n";

        // Foreign keys
        $add_fk_key_str = '';
        if(array_key_exists($table_name, $this->foreign_keys))
        {
            foreach ($this->foreign_keys[$table_name] as $fk) 
            {
                $column = $fk['column'];
                $ref_table = $fk['ref_table']; 
                $ref_column = $fk['ref_column'];
                $on_delete = $fk['on_delete'];
                $on_update = $fk['on_update'];

                $add_fk_key_str .= "\t\t" . '$this->db->query("ALTER TABLE `'.$table_name.'` ADD FOREIGN KEY(`'.$column.'`) REFERENCES '.$ref_table.'(`'.$ref_column.'`) ON DELETE '.$on_delete.' ON UPDATE '.$on_update.'");'. "\n";
            }
        }

        $str .= ($add_fk_key_str !== '') ? "\n\t\t" . '// Add foreign Key.' . "\n" . $add_fk_key_str : '';

        $str .= "\n\t" . '}' . "\n";

        return $str;
    }

    /**
     * Base on table name create migration down function
     *
     * @param string $table_name table name
     *
     * @return string
     */
    public function get_function_down_content($table_name)
    {
        $function_content = "\n\t" . '/**' . "\n";
        $function_content .= "\t" . ' * down (drop tables)' . "\n";
        $function_content .= "\t" . ' *' . "\n";
        $function_content .= "\t" . ' * @return void' . "\n";
        $function_content .= "\t" . ' */' . "\n";

        $function_content .= "\t" . 'public function down()' . "\n";
        $function_content .= "\t" . '{' . "\n";

        if($this->file_per_table === FALSE)
        {
            foreach ($this->migration_table_set as $table_name)
            {
                $function_content .= "\t\t" . '// Drop table ' . $table_name . "\n";
                $function_content .= "\t\t" . '$this->dbforge->drop_table("' . $table_name . '", TRUE);' . "\n";
            }
        }
        else
        {
            $function_content .= "\t\t" . '// Drop table ' . $table_name . "\n";
            $function_content .= "\t\t" . '$this->dbforge->drop_table("' . $table_name . '", TRUE);' . "\n";
        }

        $function_content .= "\t" . '}' . "\n";

        return $function_content;
    }

    /**
     * Base on table name create migration up function
     *
     * @param $table_name
     *
     * @return string
     */
    public function get_function_up_content_bulk()
    {
        $str = "\n\t" . '/**' . "\n";
        $str .= "\t" . ' * up (create table)' . "\n";
        $str .= "\t" . ' *' . "\n";
        $str .= "\t" . ' * @return void' . "\n";
        $str .= "\t" . ' */' . "\n";

        $str .= "\t" . 'public function up()' . "\n";
        $str .= "\t" . '{' . "\n";

        foreach ($this->migration_table_set as $table_name)
        {
            

            $query = $this->_ci->db->query("SHOW FULL FIELDS FROM {$this->_ci->db->dbprefix($table_name)} FROM {$this->_db_name}");
            
            if ($query->result() === NULL)
            {
                return FALSE;
            }

            $columns = $query->result_array();//获取列数据

            $add_key_str = '';

            $add_field_str = "\t\t" . '$this->dbforge->add_field(array(' . "\n";

            foreach ($columns as $column)
            {
                // field name
                $add_field_str .= "\t\t\t'{$column['Field']}' => array(" . "\n";

                preg_match('/^(\w+)\(([\d]+(?:,[\d]+)*)\)/', $column['Type'], $match);

                if($match === [])
                {
                    preg_match('/^(\w+)/', $column['Type'], $match);
                }

                $add_field_str .= "\t\t\t\t'type' => '" . strtoupper($match[1]) . "'," . "\n";

                if(isset($match[2]))
                {
                    switch (strtoupper($match[1]))
                    {
                        //type enum need extra handle
                        case 'ENUM':
                            $enum_constraint_str = str_replace(',', ', ', $match[2]);
                            $add_field_str .= "\t\t\t\t'constraint' => [" . $enum_constraint_str . "],\n";
                            break;
                        default:
                            $add_field_str .= "\t\t\t\t'constraint' => '" . strtoupper($match[2]) . "'," . "\n";
                            break;
                    }
                }

                $add_field_str .= (strstr($column['Type'], 'unsigned')) ? "\t\t\t\t'unsigned' => TRUE," . "\n" : '';

                $add_field_str .= ((string) $column['Default'] !== '') ? "\t\t\t\t'default' => '" . $column['Default'] . "'," . "\n" : '';

                $add_field_str .= ((string) $column['Comment'] !== '') ? "\t\t\t\t'comment' => '" . str_replace("'", "\\'", $column['Comment']) . "',\n" : '';

                $add_field_str .= ($column['Null'] !== 'NO') ? "\t\t\t\t'null' => TRUE," . "\n" : '';

                $add_field_str .= ($column['Key'] == 'UNI') ? "\t\t\t\t'unique' => TRUE," . "\n" : '';

                $add_field_str .= "\t\t\t)," . "\n";

                if ($column['Key'] == 'PRI')
                {
                    $add_key_str .= "\t\t" . '$this->dbforge->add_key("' . $column['Field'] . '", TRUE);' . "\n";
                } 
                else if ($column['Key'] == 'MUL')
                {
                    $add_key_str .= "\t\t" . '$this->dbforge->add_key("' . $column['Field'] . '");' . "\n";
                }   

            }            

            $add_field_str .= "\t\t));" . "\n";

            $str .= "\n\t\t" . '// Add Fields.' . "\n";
            $str .= $add_field_str;

            $str .= ($add_key_str !== '') ? "\n\t\t" . '// Add Primary Key.' . "\n" . $add_key_str : '';

            // create db

            $query = $this->_ci->db->query(' SHOW TABLE STATUS WHERE Name = \'' . $table_name . '\'');

            $engines = $query->row_array();

            $attributes_str = "\n\t\t" . '$attributes = array(' . "\n";;
            $attributes_str .= ((string) $engines['Engine'] !== '') ? "\t\t\t'ENGINE' => '" . $engines['Engine'] . "'," . "\n" : '';
            $attributes_str .= ((string) $engines['Comment'] !== '') ? "\t\t\t'COMMENT' => '\\'" . str_replace("'", "\\'", $engines['Comment']) . "'\\'',\n" : '';
            $attributes_str .= "\t\t" . ');' . "\n";

            $str .= "\n\t\t" . '// Table attributes.' . "\n";
            $str .= $attributes_str;

            $str .= "\n\t\t" . '// Create Table ' . $table_name . "\n";
            $str .= "\t\t" . '$this->dbforge->create_table("' . $table_name . '", TRUE, $attributes);' . "\n";

            // Foreign keys
            $add_fk_key_str = '';
            if(array_key_exists($table_name, $this->foreign_keys))
            {
                foreach ($this->foreign_keys[$table_name] as $fk) 
                {
                    $column = $fk['column'];
                    $ref_table = $fk['ref_table']; 
                    $ref_column = $fk['ref_column'];
                    $on_delete = $fk['on_delete'];
                    $on_update = $fk['on_update'];

                    $add_fk_key_str .= "\t\t" . '$this->db->query("ALTER TABLE `'.$table_name.'` ADD FOREIGN KEY(`'.$column.'`) REFERENCES '.$ref_table.'(`'.$ref_column.'`) ON DELETE '.$on_delete.' ON UPDATE '.$on_update.'");'. "\n";
                }
            }

            $str .= ($add_fk_key_str !== '') ? "\n\t\t" . '// Add foreign Key.' . "\n" . $add_fk_key_str : '';

        }

        $str .= "\n\t" . '}' . "\n";

        return $str;
    }

}

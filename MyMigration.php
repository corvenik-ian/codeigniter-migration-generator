<?php

/**
 * User: Ian Jiang
 * Date: 2016/08/21
 */

class MyMigration
{
    /**
     * @var CI_Controller
     */
    private $_ci = NULL;

    /**
     * @var string migration folder name
     */
    private   $_migration_folder_name = 'migrations';

    /**
     * @var array timestamp_set
     */
    private   $_timestamp_set = array();

    /**
     * @var string
     */
    protected $tables = '*';

    /**
     * @var bool
     */
    protected $write_file = TRUE;

    /**
     * @var string
     */
    protected $path = '';

    /**
     * @var array
     */
    protected $skip_tables = array();

    /**
     * @var bool
     */
    protected $add_view = FALSE;


    /**
     * MyMigration constructor.
     */
    public function __construct()
    {
        // Get Codeigniter Object
        if( ! isset($this->_ci))
        {
            $this->_ci =& get_instance();
        }

        $this->path = APPPATH . $this->_migration_folder_name;

        $this->_ci->load->database();
    }

    /**
     * Generate Migrations Files
     *
     * @param string $tables
     *
     * @return boolean|string
     */
    public  function generate($tables = null)
    {
        //准备$this->tables
        if (null == $tables || '*' == $tables)
        {
            $query = $this->_ci->db->query('SHOW full TABLES FROM ' . $this->_ci->db->protect_identifiers($this->_ci->db->database));

            $tmp = array();

            if ($query->num_rows() > 0)
            {
                foreach ($query->result_array() as $row)
                {
                    $table_name = 'Tables_in_' . $this->_ci->db->database;

                    if (isset($row[$table_name]))
                    {

                        /* check if table in skip arrays, if so, go next */
                        if (in_array($row[$table_name], $this->skip_tables))
                        {
                            continue;
                        }

                        /* check if views to be migrated */
                        if ($this->add_view)
                        {
                            ## not implemented ##
                            //$retval[] = $row[$table_name];
                        }
                        else
                        {
                            /* skip views */
                            if (strtolower($row['Table_type']) == 'view')
                            {
                                continue;
                            }

                            $tmp[] = $row[$table_name];
                        }
                    }
                }
            }

            for($i = 0, $cnt = count($tmp); $i < $cnt; $i++)
            { 
                //去除表前缀
                $tmp[$i]= str_ireplace($this->_ci->db->dbprefix, "", $tmp[$i]); //大小写不敏感
            }

            $this->tables = $tmp;
        }
        else
        {
            $this->tables = is_array($tables) ? $tables : explode(',', $tables);
        }

        // create migration file or override it.
        foreach ($this->tables as $table_name)
        {
            $file_content = $this->getFileContent($table_name);

            if($this->write_file)
            {
                if (NULL != $file_content)
                {
                    $this->writeFile($table_name, $file_content);
                    continue;
                }
            }

        }

        echo "Create migration success!";

        return TRUE;
    }

    /**
     * getFileContent
     *
     * @param string $table_name table name
     *
     * @return string FileContent
     */
    public function getFileContent($table_name)
    {
        $file_content = '<?php ';
        $file_content .= 'defined(\'BASEPATH\') OR exit(\'No direct script access allowed\');' . "\n\n";
        $file_content .= "class Migration_create_{$table_name} extends CI_Migration" . "\n";
        $file_content .= '{' . "\n";
        $file_content .= $this->up($table_name);
        $file_content .= $this->down($table_name);
        $file_content .= "\n" . '}' . "\n";

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
    public function writeFile($table_name, $file_content)
    {
        $file = $this->openFile($table_name);
        fwrite($file, $file_content);
        fclose($file);
    }

    /**
     * openFile
     *
     * @param string $table_name table name
     *
     * @return bool|string
     */
    public function openFile($table_name)
    {
        // get timestamp
        $query = $this->_ci->db->query(' SHOW TABLE STATUS WHERE Name = \'' . $table_name .'\'');

        $engines = $query->row_array();

        $timestamp = date('YmdHis', strtotime($engines['Create_time']));

        while(in_array($timestamp, $this->_timestamp_set))
        {
            $timestamp += 1;
        }


        $file_path = $this->path . '/' . $timestamp .'_create_' . $table_name . '.php';

        // Open for reading and writing.
        // Place the file pointer at the beginning of the file and truncate the file to zero length.
        // If the file does not exist, attempt to create it.
        $file = fopen($file_path, 'w+');

        if ( ! $file)
        {
            return FALSE;
        }

        $this->_timestamp_set[] = $timestamp;

        return $file;
    }

    /**
     * Base on table name create migration up function
     *
     * @param $table_name
     *
     * @return string|void
     */
    public function up($table_name)
    {
        $str = "\n\t" . '/**' . "\n";
        $str .= "\t" . ' * up (create table)' . "\n";
        $str .= "\t" . ' *' . "\n";
        $str .= "\t" . ' * @return void' . "\n";
        $str .= "\t" . ' */' . "\n";

        $str .= "\t" . 'public function up()' . "\n";
        $str .= "\t" . '{' . "\n";

        $query = $this->_ci->db->query('describe ' . $this->_ci->db->database . '.' . $this->_ci->db->dbprefix($table_name));

        // 如果没有结果，直接返回
        if (null == $query->result())
        {
            return FALSE;
        }

        $columns = $query->result_array();//获取列数据

        $add_key_str = '';

        $add_field_str = "\t\t" . '$this->dbforge->add_field(array(' . "\n";

        foreach ($columns as $column)
        {
            // Field Begin

            // field name
            $add_field_str .= "\t\t\t'{$column['Field']}' => array(" . "\n";

            preg_match('/^(\w+)\(([\d]+)\)/', $column['Type'], $match);

            if($match === [])
            {
                preg_match('/^(\w+)/', $column['Type'], $match);
            }

            $add_field_str .= "\t\t\t\t'type' => '" . strtoupper($match[1]) . "'," . "\n";

            $add_field_str .= (isset($match[2])) ? "\t\t\t\t'constraint' => '" . strtoupper($match[2]) . "'," . "\n" : '';

            $add_field_str .= (strstr($column['Type'], 'unsigned')) ? "\t\t\t\t'unsigned' => TRUE," . "\n" : '';

            $add_field_str .= ((string) $column['Default'] !== '') ? "\t\t\t\t'default' => '" . $column['Default'] . "'," . "\n" : '';

            $add_field_str .= ($column['Null'] !== 'NO') ? "\t\t\t\t'null' => TRUE," . "\n" : '';

//            die();
//
//            $add_field_str .= "\t\t" . '$this->dbforge->add_field("' . "`$column[Field]` $column[Type] " . ($column['Null'] == 'NO' ? 'NOT NULL' : 'NULL');
//            #  timestamp 默认值不需要加引号
//            $add_field_str .= ($column['Default'] ? ' DEFAULT ' . ($column['Type'] == 'timestamp' ? $column['Default'] : '\'' . $column['Default'] . '\'') : '') . " $column[Extra]\");" . "\n";

            $add_field_str .= "\t\t\t)," . "\n";
            // Field End

            if ($column['Key'] == 'PRI')
            {
                $add_key_str .= "\t\t" . '$this->dbforge->add_key("' . $column['Field'] . '",true);' . "\n";
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
        $attributes_str .= ((string) $engines['Comment'] !== '') ? "\t\t\t'COMMENT' => \"'" . $engines['Comment'] . "'\"," . "\n" : '';
        $attributes_str .= "\t\t" . ');' . "\n";

        $str .= "\n\t\t" . '// Table attributes.' . "\n";
        $str .= $attributes_str;

        $str .= "\n\t\t" . '// Create Table ' . $table_name . "\n";
        $str .= "\t\t" . '$this->dbforge->create_table("' . $table_name . '", TRUE, $attributes);' . "\n";

        // date部分暫時不塞
//        $data = $this->tableData($table_name);
//        $str .=$data;
        $str .= "\n\t" . '}' . "\n";

        return $str;
    }

    /**
     * 根据table name 获取表数据，给migration up 使用
     *
     * @param string $table_name table name
     *
     * @return bool|string
     */
    public function tableData($table_name)
    {
        $str = "\n\t\t" . "//GET {$table_name} data";
        $query = $this->_ci->db->get($table_name);
        $result = $query->result();

        if (NULL == $result)
        {
            return FALSE;
        }

        $data = '';

        foreach ($result as $row)
        {

            $data = '' == $data ? '' : $data . ',';

            $data .= "\n\t\t\t\t" . 'array(';

            foreach ($row as $key => $val)
            {
                $data .= "\n\t\t\t\t\t" . "'$key'=>'$val',";
            }

            $data .= "\n\t\t\t\t" .")";
        }

        $data = "\n\t\t" . '$data = array(' . $data;
        $data .= "\n\t\t" . ");";

        $str .= $data . "\n";

        $str .= "\t\t". "//INSERT bath data" . "\n";
        $str .= "\t\t".'$this->db->insert_batch(\'' . $table_name . '\',$data);' . "\n";

        return $str;
    }

    /**
     * Base on table name create migration down function
     *
     * @param string $table_name table name
     *
     * @return string
     */
    public function down($table_name)
    {
        $str = "\n\t" . '/**' . "\n";
        $str .= "\t" . ' * down (drop table)' . "\n";
        $str .= "\t" . ' *' . "\n";
        $str .= "\t" . ' * @return void' . "\n";
        $str .= "\t" . ' */' . "\n";

        $str .= "\t" . 'public function down()' . "\n";
        $str .= "\t" . '{' . "\n";
        $str .= "\t\t" . '// Drop table ' . $table_name . "\n";
        $str .= "\t\t" . '$this->dbforge->drop_table("' . $table_name . '", TRUE);' . "\n";
        $str .= "\t" . '}' . "\n";

        return $str;
    }
}

<?php

/**
 * User: Ian Jiang
 * Date: 2016/08/21
 */

class MyMigration
{
    private   $_ci;
    protected $tables = '*';
    protected $write_file = TRUE;
    protected $path = '';
    protected $skip_tables = array();
    protected $add_view = FALSE;
    private   $_migration_folder_name = 'migrations';
    private   $_timestamp_set = array();
 
    function __construct()
    {
        // Get Codeigniter Object
        if(!isset($this->_ci))
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
     * @return boolean|string
     */
    function generate($tables = null)
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

        //循环生成文件
        foreach ($this->tables as $table)
        {
            unset($migration);
            log_message('debug', print_r($table, TRUE));
            $str = $this->getFileString($table, $this->up($table), $this->down($table));

            if($this->write_file)
            {
                if (NULL != $str)
                {
                    $result = $this->writeFile($table, $str);
                    continue;
                }
            }
            else
            {
                //TODU 输出
                var_dump( $str);
            }

        }


        echo "Create migration success!";
        return TRUE;
    }

    /**
     * 获取文件全部内容
     *
     * @param $table
     * @param $up
     * @param $down
     *
     * @return bool
     */
    function getFileString($table, $up, $down)
    {
        $str = '<?php ';
        $str .= 'defined(\'BASEPATH\') OR exit(\'No direct script access allowed\');' . "\n\n";
        $str .= "class Migration_create_$table extends CI_Migration" . "\n";
        $str .= '{' . "\n";
        $str .= $up;
        $str .= $down;
        $str .= '}';

        return $str;
    }

    /**
     * 写入文件
     *
     * @param $table
     * @param $str
     *
     * @return void
     */
    function  writeFile($table,$str)
    {
        $file = $this->openFile($table);
        fwrite($file, $str);
        fclose($file);
    }

    /**
     * 验证是否有写权限
     *
     * @param $fileName
     *
     * @return bool
     */
    function checkPermission($fileName)
    {
        //file permissions
        if ($this->write_file)
        {
            if (!is_dir($this->path) OR !is_really_writable($this->path))
            {
                $msg = "can not write migration file to " . $this->path;
                log_message('error', $msg);
                return FALSE;
            }
//
        }
        return TRUE;
    }

    /**
     * 创建文件
     *
     * @param $fileName
     *
     * @return void
     */
    function openFile($fileName)
    {
        // get timestamp
        $query = $this->_ci->db->query(' SHOW TABLE STATUS WHERE Name = \'' . $fileName .'\'');

        $engines = $query->row_array();

        $timestamp = date('YmdHis', strtotime($engines['Create_time']));

        while(in_array($timestamp, $this->_timestamp_set))
        {
            $timestamp += 1;
        }


        $file_path = $this->path . '/' . $timestamp .'_create_' . $fileName . '.php';
        $file = fopen($file_path, 'w+');

        if ( ! $file)
        {
            $msg = 'No File';
            log_message('error', $msg);
//            echo $msg;
            return FALSE;
        }

        $this->_timestamp_set[] = $timestamp;

        return $file;
    }

    /**
     * 根据table name 创建 migration up function
     *
     * @param $tableName
     *
     * @return string|void
     */
    function up($tableName)
    {
        $str = "\n\t" . '/**' . "\n";
        $str .= "\t" . ' * up (create table)' . "\n";
        $str .= "\t" . ' *' . "\n";
        $str .= "\t" . ' * @return void' . "\n";
        $str .= "\t" . ' */' . "\n";

        $str .= "\t" . 'public function up()' . "\n";
        $str .= "\t" . '{' . "\n";

        $query = $this->_ci->db->query('describe ' . $this->_ci->db->database . '.' . $this->_ci->db->dbprefix($tableName) );

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

        $query = $this->_ci->db->query(' SHOW TABLE STATUS WHERE Name = \'' . $tableName . '\'');

        $engines = $query->row_array();

        $attributes_str = "\n\t\t" . '$attributes = array(' . "\n";;
        $attributes_str .= ((string) $engines['Engine'] !== '') ? "\t\t\t'ENGINE' => '" . $engines['Engine'] . "'," . "\n" : '';
        $attributes_str .= ((string) $engines['Comment'] !== '') ? "\t\t\t'COMMENT' => '" . $engines['Comment'] . "'," . "\n" : '';
        $attributes_str .= "\t\t" . ');' . "\n";

        $str .= "\n\t\t" . '// Table attributes.' . "\n";
        $str .= $attributes_str;

        $str .= "\n\t\t" . '// Create Table ' . $tableName . "\n";
        $str .= "\t\t" . '$this->dbforge->create_table("' . $tableName . '", TRUE, $attributes);' . "\n";

        // date部分暫時不塞
//        $data = $this->tableData($tableName);
//        $str .=$data;
        $str .= "\n\t" . ' }' . "\n";

        return $str;
    }

    /**
     * 根据table name 获取表数据，给migration up 使用
     *
     * @param $tableName
     *
     * @return void
     */
    function tableData($tableName)
    {
        $str = "\n\t\t" . "//GET $tableName data";
        $query = $this->_ci->db->get($tableName);
        $result = $query->result();
        if (null == $result) {
            $str .= "\n\t\t" . "//no data";
            return FALSE;
        }
        $data = '';
        foreach ($result AS $row) {

            $data = '' == $data ? '' : $data . ',';

            $data .= "\n\t\t\t\t" . 'array(';
            foreach ($row AS $key => $val) {
                $data .= "\n\t\t\t\t\t" . "'$key'=>'$val',";
            }
            $data .= "\n\t\t\t\t" .")";
        }
        $data = "\n\t\t" . '$data = array(' . $data;
        $data .= "\n\t\t" . ");";

        $str .= $data . "\n";

        $str .="\t\t". "//INSERT bath data" . "\n";
        $str .="\t\t".'$this->db->insert_batch(\''.$tableName.'\',$data);' ."\n";

        return $str;
    }

    /**
     * 根据table name 创建 migration down function
     *
     * @param $tableName
     *
     * @return string
     */
    function down($tableName)
    {
        $str = "\n\t" . '/**' . "\n";
        $str .= "\t" . ' * down (drop table)' . "\n";
        $str .= "\t" . ' *' . "\n";
        $str .= "\t" . ' * @return void' . "\n";
        $str .= "\t" . ' */' . "\n";

        $str .= "\t" . 'public function down()' . "\n";
        $str .= "\t" . '{' . "\n";
        $str .= "\t\t" . '// Drop table ' . $tableName . "\n";
        $str .= "\t\t" . '$this->dbforge->drop_table("' . $tableName . '", TRUE);' . "\n";
        $str .= "\t" . '}' . "\n";
        return $str;
    }
}

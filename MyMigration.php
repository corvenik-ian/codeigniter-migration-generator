<?php

/**
 
 * User: LingFeng
 * Date: 2015/11/16
 * Time: 22:49
 */
class MyMigration
{
    protected $CI;
    var $tables = '*';
    var $write_file = TRUE;
    protected $path = '';
    var $skip_tables = array();
    var $add_view = false;
 
    function __construct()
    {
        // parent::__construct();
        isset($this->CI) OR $this->CI =& get_instance();
        $this->path = APPPATH . 'migrations';
        $this->CI->load->database();

    }

    /**
     * 生成migrations file
     *
     * @param string $tables
     * @return boolean|string
     */
    function generate($tables = null)
    {
        //准备$this->tables
        if (null == $tables || '*' == $tables) {
            $query = $this->CI->db->query('SHOW full TABLES FROM ' . $this->CI->db->protect_identifiers($this->CI->db->database));

            $tmp = array();
            if ($query->num_rows() > 0) {
                foreach ($query->result_array() as $row) {
                    $tablename = 'Tables_in_' . $this->CI->db->database;
                    if (isset($row[$tablename])) {
                        /* check if table in skip arrays, if so, go next */
                        if (in_array($row[$tablename], $this->skip_tables))
                            continue;
                        /* check if views to be migrated */
                        if ($this->add_view) {
                            ## not implemented ##
                            //$retval[] = $row[$tablename];
                        } else {
                            /* skip views */
                            if (strtolower($row['Table_type']) == 'view') {
                                continue;
                            }
                            $tmp[] = $row[$tablename];
                        }
                    }
                }
            }
            for($i=0;$i<count($tmp);$i++){ //去除表前缀
                $tmp[$i]= str_ireplace($this->CI->db->dbprefix,"",$tmp[$i]); //大小写不敏感
            }

            $this->tables = $tmp;
        } else {
            $this->tables = is_array($tables) ? $tables : explode(',', $tables);
        }

        //循环生成文件
        foreach ($this->tables as $table) {
            unset($migration);
            log_message('debug', print_r($table, true));
            $str = $this->getFileString($table, $this->up($table), $this->down($table));
            if($this->write_file){

                if (NULL != $str) {
                    $result = $this->writeFile($table,$str);
                    continue;
                }
            }else{
                //TODU 输出
                var_dump( $str);
            }

        }


        echo "Create migration success!";
        return true;
    }

    /**
     * 获取文件全部内容
     * @param $table
     * @param $up
     * @param $down
     * @return bool
     */
    function getFileString($table, $up, $down)
    {
        $str = '<?php ';
        $str .= 'defined(\'BASEPATH\') OR exit(\'No direct script access allowed\');' . "\n\n";
        $str .= "class Migration_create_$table extends CI_Migration {" . "\n";
        $str .= $up;
        $str .= $down;
        $str .= '}';

        return $str;
    }

    /**
     * 写入文件
     * @param $table
     * @param $str
     */
    function  writeFile($table,$str){
        $file = $this->openFile($table);
        fwrite($file, $str);
        fclose($file);
    }

    /**
     * 验证是否有写权限
     * @param $fileName
     * @return bool
     */
    function checkPermission($fileName)
    {
        //file permissions
        if ($this->write_file) {
            if (!is_dir($this->path) OR !is_really_writable($this->path)) {
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
     * @param $fileName
     */
    function openFile($fileName)
    {

        $file_path = $this->path . '/create_' . $fileName . '.php';
        $file = fopen($file_path, 'w+');
        if (!$file) {
            $msg = 'No File';
            log_message('error', $msg);
//            echo $msg;
            return FALSE;
        }
        return $file;

    }

    /**
     * 根据table name 创建 migration up function
     * @param $tableName
     * @return string|void
     */
    function up($tableName)
    {
        $str = "\n\t" . 'public function up() {' . "\n";
        $str .= "\n\t\t" . "// Drop table '$tableName' if it exists";
        $str .= "\n\t\t" . '$this->dbforge->drop_table(\'' . $tableName . '\', TRUE);';


        $query = $this->CI->db->query('describe ' . $this->CI->db->database . '.' . $this->CI->db->dbprefix($tableName) );
        // 如果没有结果，直接返回
        if (null == $query->result()) return;

        $columns = $query->result_array();//获取列数据
        $query = $this->CI->db->query(' SHOW TABLE STATUS WHERE Name = \'' . $tableName . '\'');
        $engines = $query->row_array();
        $str .= "\n\t\t" . '## Create Table ' . $tableName . "\n";
        foreach ($columns as $column) {
            $str .= "\t\t" . '$this->dbforge->add_field("' . "`$column[Field]` $column[Type] " . ($column['Null'] == 'NO' ? 'NOT NULL' : 'NULL') .
                (                    #  timestamp 默认值不需要加引号
                $column['Default'] ? ' DEFAULT ' . ($column['Type'] == 'timestamp' ? $column['Default'] : '\'' . $column['Default'] . '\'') : ''
                )
                . " $column[Extra]\");" . "\n";
            if ($column['Key'] == 'PRI')
                $str .= "\t\t" . '$this->dbforge->add_key("' . $column['Field'] . '",true);' . "\n";
        }
        $str .= "\t\t" . '$this->dbforge->create_table("' . $tableName . '", TRUE);' . "\n";
        if (isset($engines['Engine']) and $engines['Engine'])
            $str .= "\t\t" . '$this->db->query(\'ALTER TABLE  ' . $this->CI->db->protect_identifiers($tableName) . ' ENGINE = ' . $engines['Engine'] . '\');';
        if (isset($engines['Comment']) and $engines['Comment'])
            $str .= "\t\t" . '$this->db->query(\'ALTER TABLE  ' . $this->CI->db->protect_identifiers($tableName) . ' COMMENT = ' . $engines['Comment'] . '\');';

        $data = $this->tableData($tableName);
        $str .=$data;
        $str .= "\n\t" . ' }' . "\n";
        return $str;
    }

    /**
     * 根据table name 获取表数据，给migration up 使用
     * @param $tableName
     */
    function tableData($tableName)
    {
        $str = "\n\t\t" . "//GET $tableName data";
        $query = $this->CI->db->get($tableName);
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
     * @param $tableName
     * @return string
     */
    function down($tableName)
    {
        $str = "\n\t" . 'public function down() {'."\n";
        $str .= "\t\t" . '### Drop table ' . $tableName . ' ##' . "\n";
        $str .= "\t\t" . '$this->dbforge->drop_table("' . $tableName . '", TRUE);' . "\n";
        $str .= "\t" . '}' . "\n";
        return $str;
    }
}

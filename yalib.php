<?php
/**
 * PDO-MySQLのラッパクラス
 * ほぼ全てがメソッドチェーンで動作する
 *
 * メソッド本体には可能な限り引数, 戻り値のコメントをつける
 * 短縮名や別名の場合は、その旨記述する事
 * 特に戻り値が無くても良いメソッドでは $this を返す。
 * また、可能な限り $this を返せるように務める
 */
class yalib {
	private static $instance = array();
	private $pdo = false;
	private $conf = false;
	private $stmt = false;
	private $inLoop = false;
	private $preparedSql = '';
	private $boundSql = '';
	private $boundValues = array();

	//
	public static function factory($confname = false){
	  $confname = $confname ? $confname : 'default';
	  return new yalib($confname);
	}
	
	/**
	 * シングルトンインスタンスの生成
	 * @param optional string $confname
	 * @return instance
	 */
	public static function getInstance($confname = false){
		$confname = $confname ? $confname : 'default';
		if (!isset(yalib::$instance[$confname])) {
			self::$instance[$confname] = new yalib($confname, 'confname');
		}
		return self::$instance[$confname];
	}
	
	/**
	 * getInstance() の短縮名
	 */
	public static function gi($confname = false) {
		return self::getInstance($confname);
	}

	/**
	 * DSN表記によるシングルトンインスタンス生成
	 * @param string $dsn
	 * @return instance
	 */
	public static function getInstanceByDsn($dsn) {
		$keyname = 'dsn_'.md5($dsn);
		if (!isset(yalib::$instance[$keyname])) {
			self::$instance[$keyname] = new yalib($dsn, 'dsn');
		}
		return self::$instance[$keyname];
	}

	/**
	 * getInstanceByDsn() の短縮名
	 */
	public static function giDsn($dsn) {
		return self::getInstanceByDsn($dsn);
	}

	/**
	 * インスタンス初期化
	 * $config は config.iniのセクション名
	 * 又は DSN文字列
	 * $confType にはconfname又はdsnを渡す
	 * @param string $conf
	 * @param string $confType
	 */
	private function __construct($conf = 'default', $confType = 'confname') {
		switch ($confType) {
			case 'dsn':
				$this->_dsnToConfig($conf)->_connect();
				break;
			case 'array':
				$this->conf = $conf;
				$this->_connect();
				break;
			case 'confname':
			default:
				$this->_loadConfig($conf)->_connect();
				break;
		}
	}

	/**
	 * 設定ファイルのロード
	 * $confname に該当するiniのセクションが無ければ例外を投げる
	 * @param optional string $confname
	 * @return selfObject
	 */
	private function _loadConfig($confname = false) {
		$config = parse_ini_file(dirname(__FILE__).'/config.ini', true);
		if ($confname && isset($config[$confname])) {
			foreach ($config[$confname] as $key => $val) {
				$config[$confname][$key] = trim($val, '\'"');
			}
			$this->conf = $config[$confname];
		}
		else if ($confname !== false && !isset($config[$confname])) {
	    throw new Exception('$confname is not found');
		}
		else {
			$this->conf = $config['default'];
		}
		return $this;
	}

	/**
	 * DSNをConfig形式に直す
	 * @return selfObject
	 */
	private function _dsnToConfig($dsn) {
		$tmp = parse_url($dsn);
		$this->conf = array(
			'schema' => str_replace('/', '', $tmp['path']),
			'host' => $tmp['host'],
			'port' => isset($tmp['port']) ? $tmp['port'] : 3306,
			'username' => $tmp['user'],
			'password' => $tmp['pass']
		);
		return $this;
	}

	private function _errorHandle($e){
	  $this->_setBoundSql();
	  $msg = $e->getMessage().PHP_EOL.
	  'Error happend.' . PHP_EOL;
	  $this->getBoundSQL() . PHP_EOL;
	  throw new Exception($msg);
	}
	
	/**
	 * データベースへの接続
	 * @return selfObject
	 */
	private function _connect() {
		if ($this->pdo === false) {
			$dsn = 'mysql:dbname='.$this->conf['schema']
				.';host='.$this->conf['host'].';port='.$this->conf['port'];
			try {
				$this->pdo = new PDO(
					$dsn,
					$this->conf['username'],
					$this->conf['password']
				);
			} catch(PDOException $e) {
				throw new Exception($e->getMessage());
			}
		}
		return $this;
	}

	/**
	 * メンバ変数のboundSqlをセットする
	 * executeが呼ばれた時にこの関数でセットするのが好ましい
	 * @return selfObject
	 */
	private function _setBoundSql(){
		foreach($this->boundValues as $key => $bv) { // bv=>boundValue
			if((isset($bv['type']) && $bv['type'] == PDO::PARAM_INT) ||
				 (isset($bv['value']) && is_numeric($bv['value']))) {
				$value = $bv['value'];
			}
			else if((isset($bv['type']) && $bv['type'] == PDO::PARAM_STR) ||
							(isset($bv['value']) && is_string($bv['value']))) {
				$value = "'".$bv['value']."'";
			}
			else{
				$value = $bv['value'];
			}
			$this->boundSql = str_replace($bv['key'], $value, $this->boundSql);
		}
		$this->boundValues = array();
		return $this;
	}

	/**
	 * PDOのprepareを実行する
	 * @param string $query
	 * @return selfObject
	 */
	public function prepare($query) {
	  if($this->inLoop && $this->preparedSql == $query){
	    // まだループの最中なので何もしない
	  }else{
	    $this->preparedSql = $this->boundSql = $query;
	    $this->inLoop = false;
	    $this->stmt = false;
	    try {
	      $this->stmt = $this->pdo->prepare($query);
	    } catch(PDOException $e) {
	      throw new Exception($e->getMessage());
	    }
	  }
	  return $this;
	}

	/**
	 * prepare() の短縮名
	 */
	public function p($query) {
		return $this->prepare($query);
	}

	/**
	 * 引数による1件づつの変数割り当て
	 * bindValuesは変数タイプを指定できないので、必要ならこちらを使う
	 * @param string $key
	 * @param mixed $value
	 * @param optional int $type
	 * @return selfObject
	 */
	public function bindValue($key, $value, $type = false) {
		$this->stmt->bindValue($this->_insertColon($key), $value, $type);
		$this->boundValues[] = array
		  ('key' => $this->_insertColon($key),
		   'value' => $value,
		   'type' => $type);
		return $this;
	}

	/**
	 * 配列による複数の変数割り当て
	 * 変数タイプは指定できないので、bindValueを使用する事
	 * @param array $values
	 * @return selfObject
	 */
	public function bindValues($values) {
	  if (!is_array($values)) {
	    throw new Exception('$values is not an array');
	  }
	  foreach ($values as $key => $val) {
	    $key = $this->_insertColon($key);
	    if (false === $this->stmt->bindValue($key, $val)) {
	      throw new Exception('bindValue failed');
	    }
	    $this->boundValues[] = array
	      ('key' => $this->_insertColon($key),
	       'value' => $val);
	  }
	  return $this;
	}

	/**
	 * bindValue(), bindValues() などで、キーの先頭が”:”でなければ
	 * 自動的に付加する
	 * @param string $str
	 * @return string
	 */
	private function _insertColon($str) {
		if (isset($str[0]) && $str[0] !== ':') {
			$str = ':'.$str;
		}
		return $str;
	}

	/**
	 * bindValues(), bindValue() の別名, 短縮名
	 * 引数1が配列の場合はbindValues(), そうでなければbindValue()を使用する
	 */
	public function bv($arg1, $arg2 = false, $arg3 = false) {
		if (is_array($arg1)) {
			return $this->bindValues($arg1);
		}
		return $this->bindValue($arg1, $arg2, $arg3);
	}

	/**
	 * クエリのバッファリングを有効,無効化する
	 * 第一引数にtrueを指定すると有効に、falseを指定すると無効にする
	 * @link http://www.php.net/manual/ja/pdo.setattribute.php
	 * @param bool $value
	 * @return selfObject
	 */
	public function setQueryBuffer($value) {
		$this->setPDOAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $value);
		return $this;
	}

	/**
	 * PDOのATTRを設定する
	 * @param int $attr
	 * @param mixed $value
	 * @return selfObject
	 */
	public function setPDOAttribute($attr, $value){
		$this->pdo->setAttribute($attr, $value);
		return $this;
	}

	/**
	 * 変数割り当て済みのSQLを直接実行し、1行分の結果を返す
	 * 成功時には配列, 失敗時にはfalseを返す
	 * @param string $query
	 * @return mixed
	 */
	public function fetchQuery($query) {
		try {
			if ($this->inLoop === false) {
				$this->prepare($query);
				if (false === $this->stmt->execute()) {
					throw new Eception(print_r($this->stmt->errorInfo(), true));
				}
				$this->inLoop = true;
			}
			if (false === ($row = $this->stmt->fetch(PDO::FETCH_ASSOC))) {
				$this->inLoop = false;
			}
			return $row;
		} catch(PDOException $e) {
			throw new Exception($e->getMessage());
		}
	}

	/**
	 * 変数割り当て済みのSQLを直接実行し、すべての結果を返す
	 * 成功時には配列, 失敗時にはfalseを返す
	 * @param string $query
	 * @ return mixed
	 */
	public function fetchAllQuery($query) {
		try {
			$this->prepare($query);
			if (false === $this->stmt->execute()) {
				throw new Eception(print_r($this->stmt->errorInfo(), true));
			}
			$rows = $this->stmt->fetchAll(PDO::FETCH_ASSOC);
			return $rows;
		} catch(PDOException $e) {
			throw new Exception($e->getMessage());
		}
	}
	
	/**
	 * prepare, bindValueなどを実行済みの場合に、1行分の結果を返す
	 * 成功時には配列, 失敗時にはfalseを返す
	 * @return mixed
	 */
	public function fetch($arg1 = false, $arg2 = false, $arg3 = false) {
	  if(false !== $arg1){
	    return $this->bv($arg1, $arg2, $arg3)->fetch();
	  }
		try {
			if ($this->inLoop === false) {
				if (false === $this->execute()) {
					throw new Exception(print_r($this->stmt->errorInfo(), true));
				}
				$this->inLoop = true;
			}
			if (false === ($row = $this->stmt->fetch(PDO::FETCH_ASSOC))){
				$this->inLoop = false;
			}
			return $row;
		} catch(PDOException $e) {
			throw new Exception($e->getMessage());
		}
	}
	
	/**
	 * fetch() の短縮名
	 */
	public function f($arg1 = false, $arg2 = false, $arg3 = false) {
	  return $this->fetch($arg1, $arg2, $arg3);
	}

	/**
	 * prepare, bindValueなどを実行済みの場合に、すべての結果を返す
	 * 成功時には配列, 失敗時にはfalseを返す
	 * @return mixed
	 */
	public function fetchAll($arg1 = false, $arg2 = false, $arg3 = false) {
	  if(false !== $arg1){
	    return $this->bv($arg1, $arg2, $arg3)->fetchAll();
	  }
		try {
			if (false === $this->execute()) {
				throw new Exception(print_r($this->stmt->errorInfo(), true));
			}
			$rows = $this->stmt->fetchAll(PDO::FETCH_ASSOC);

		} catch(PDOException $e) {
			throw new Exception($e->getMessage());
		}
		return $rows;
	}
	
	/**
	 * fetchAll() の短縮名
	 */
	public function fa($arg1 = false, $arg2 = false, $arg3 = false) {
	  return $this->fetchAll($arg1, $arg2, $arg3);
	}

	/**
	 * 変数割り当て済みのSQLを直接実行する
	 * @param string $query
	 * @return selfObject
	 */
	public function query($query) {
		try {
			$this->prepare($query);
			if (false === $this->stmt->execute()) {
				throw new Exception(print_r($this->stmt->errorInfo(), true));
			}
		} catch(PDOExeption $e) {
			throw new Exception($e->getMessage());
		}
		return $this;
	}

	/**
	 * query() の短縮名
	 */
	public function q($query) {
		return $this->query($query);
	}

	/**
	 * prepare, bindValueなどを実行済みの場合に、ステートメントを実行する
	 * 引数は bv() メソッドの引数に準ずる
	 * @return selfObject
	 */
	public function execute($arg1 = false, $arg2 = false, $arg3 = false) {
	  if (false !== $arg1) {
	    return $this->bv($arg1, $arg2, $arg3)->execute();
	  }
		try {
			if (false === $this->stmt->execute()) {
			  $this->_errorHandle(new Exception(print_r($this->stmt->errorInfo(), true)));
			}
		} catch(PDOExeption $e) {
		  throw new Exception($e->getMessage());
		}
		$this->_setBoundSql();
		return $this;
	}
	
	/**
	 * execute() の短縮名
	 */
	public function e($arg1 = false, $arg2 = false, $arg3 = false) {
	  return $this->execute($arg1, $arg2, $arg3);
	}

	/**
	 * 直前に実行したクエリの影響行数を返す
	 * @return int
	 */
	public function countRows() {
		return intval($this->stmt->rowCount());
	}

	/**
	 * クエリを直接実行し、行数を返す
	 * @param string $query
	 * @return int
	 */
	public function countRowsQuery($query) {
		return $this->query($query)->countRows();
	}

	/**
	 * countRows() の短縮名
	 */
	public function c() {
		return $this->countRows();
	}

	/**
	 * 直前に実行したINSERT文のIDを返す
	 * 結果が数値ならintに変換, そうでなければそのまま返す
	 * @return mixed
	 */
	public function getInsertId() {
		$id = $this->pdo->lastInsertId();
		return is_numeric($id) ? intval($id) : $id;
	}

	/**
	 * INSERT文を直接実行し、IDを返す
	 * @param string $query
	 * @return mixed
	 */
	public function getInsertIdQuery($query) {
		return $this->query($query)->getInsertId();
	}

	/**
	 * getInsertId() の短縮名
	 */
	public function gid() {
		Return $this->getInsertId();
	}
	
	/**
	 * トランザクションを開始する
	 * @return selfObject
	 */
	public function begin() {
		$this->pdo->beginTransaction();
		return $this;
	}

	/**
	 * トランザクションを終了し、コミットする
	 * @return selfObject
	 */
	public function commit() {
		$this->pdo->commit();
		return $this;
	}

	/**
	 * トランザクションを終了し、ロールバックする
	 * @return selfObject
	 */
	public function rollBack() {
		$this->pdo->rollBack();
		return $this;
	}

	/**
	 * bindValuesによって値がbindされたSQL文を取得する。
	 * 注意すべきは実際にはSQLでprepare->executeが実装、実行されているので
	 * bindValuesでbindされる値を置換しているだけ。
	 * @return string
	 */
	public function getBoundSQL(){
	  return $this->boundSql;
	}
	
	/**
	 * yalibのinstanceをリセットする
	 * 具体的には内部に保持されているinLoopやpreparedSqlをクリアする
	 * reset()はすべてのrowをfetch仕切っていない状態でさらに同じsql
	 * を発行するときに用いる。
	 * reset()を呼ばないで同じSQLを利用すると引き続き前回のsqlの結果を
	 * 返そうとしていまい、期待した結果を得ることができない。
	 *
	 * 主にLIMIT句が付いたSQLで期待した動作を行わせるために使う
	 * ex) SELECT * FROM hoge WHERE ID = :id LIMIT 1;
	 * $yalib->p($sql)->f();
	 * 上記の構文は戻り値が無くなるまで呼ばれるのじゃ無くて、一度だけ呼ばれる
	 * そうすると二度目に呼ばれた時はLIMIT 1で戻り値が無い状態でfalseが
	 * 返される。コレは恐らく期待した動作じゃない。
	 * @return selfObject
	 */
	public function reset(){
	  $this->stmt = false;
	  $this->inLoop = false;
	  $this->preparedSql = '';
	  $this->boundSql = '';
	  $this->boundValues = array();
	  return $this;
	}

}
?>

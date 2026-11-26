<?php
/**
 * IFI_Upload_Trait — Inquiry Form Inbox for Headless WordPress.
 */

defined( 'ABSPATH' ) || exit;

trait IFI_Upload_Trait {


	/**
	 * 把上传文件挪进本次提交的暂存目录，返回 array( name, tmp_path, size )
	 *
	 * 文件类型由前端负责（input 的 accept / 前端校验）；服务端只校验上传错误、
	 * 文件数量与单文件大小，并清洗文件名。
	 *
	 * @throws RuntimeException 任一文件不合法时；消息由 handle() 原样放进 JSON 响应.
	 */
	private static function stage_attachments( array $files ): array {
		$staged = array();
		if ( empty( $files ) ) {
			return $staged;
		}

		$stage_dir = self::stage_dir();
		if ( ! wp_mkdir_p( $stage_dir ) ) {
			self::reject( self::t( 'error_upload_dir' ) );
		}

		$max_files = (int) apply_filters( 'ifi_max_files', self::MAX_FILES );

		foreach ( self::file_groups() as $group => $max_size ) {
			$max_size = (int) $max_size;
			// 上限非正数视为该字段不接收附件；字段名来自过滤器的键，不存在时直接跳过
			if ( $max_size <= 0 || empty( $files[ $group ] ) ) {
				continue;
			}
			// 前端用 "group[]" 提交；单文件时 PHP 给的是标量，统一成数组
			$names  = (array) $files[ $group ]['name'];
			$tmp    = (array) $files[ $group ]['tmp_name'];
			$sizes  = (array) $files[ $group ]['size'];
			$errors = (array) $files[ $group ]['error'];

			foreach ( $names as $i => $original ) {
				$error_code = (int) ( $errors[ $i ] ?? UPLOAD_ERR_NO_FILE );
				if ( $error_code === UPLOAD_ERR_NO_FILE ) {
					continue;
				}
				if ( $error_code !== UPLOAD_ERR_OK ) {
					self::reject( self::t( 'error_upload_failed' ) );
				}
				if ( count( $staged ) >= $max_files ) {
					self::reject( self::t( 'error_upload_too_many' ) );
				}
				$tmp_path = (string) ( $tmp[ $i ] ?? '' );

				$size = @filesize( $tmp_path );
				if ( false === $size || $size <= 0 ) {
					self::reject( sprintf( self::t( 'error_upload_empty' ), (string) $original ) );
				}
				if ( (int) $sizes[ $i ] !== (int) $size ) {
					self::reject( sprintf( self::t( 'error_upload_size' ), (string) $original ) );
				}
				if ( $size > $max_size ) {
					self::reject(
						sprintf(
							self::t( 'error_upload_large' ),
							(string) $original,
							(int) ( $max_size / 1048576 )
						)
					);
				}

				$safe_name = wp_unique_filename( $stage_dir, sanitize_file_name( (string) $original ) );
				$dest      = $stage_dir . '/' . $safe_name;
				if ( ! move_uploaded_file( $tmp_path, $dest ) ) {
					self::reject( self::t( 'error_upload_store' ) );
				}
				$staged[] = array(
					'name'     => $safe_name,
					'tmp_path' => self::norm( $dest ),
					'size'     => (int) $size,
				);
			}
		}
		return $staged;
	}

	/**
	 * Abort the submission with a message that is safe to hand back as JSON.
	 *
	 * The message travels to the front end as the `message` field of a REST error, never as
	 * HTML, so escaping it here would turn an apostrophe in "John's-part.pdf" into
	 * `&#039;`. The ignore is scoped to this one throw so no other exception slips through
	 * unescaped where it does matter.
	 *
	 * @param string $message Already-translated, already-built message.
	 * @return never
	 * @throws RuntimeException Always.
	 */
	private static function reject( string $message ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as a JSON field, not printed as HTML.
		throw new RuntimeException( $message );
	}

	/** 本次提交的暂存目录（进程内固定，方便统一清理；CSPRNG 后缀避免被猜测） */
	private static function stage_dir(): string {
		static $dir = null;
		if ( $dir === null ) {
			$dir = self::norm( get_temp_dir() ) . 'ifi-stage-' . strtolower( wp_generate_password( 16, false, false ) );
		}
		return $dir;
	}

	/**
	 * 附件持久化：uploads/ifi-inquiries/{Ym}/{文章ID-随机}/，返回留档后的文件数组。
	 *
	 * 返回形状始终是 name/path/url/size 四件套——不管文件最后落在留档目录还是暂存目录。
	 * url 为空只在「确实没能归档」时出现（后台据此显示不可点），不会因为路径算错而空。
	 */
	private static function persist_attachments( array $staged, int $record_id ): array {
		if ( ! $staged ) {
			return array();
		}

		$dir   = self::record_dir( $record_id );
		$ready = wp_mkdir_p( $dir );
		$out   = array();

		if ( $ready ) {
			self::write_index_guard( self::storage_base() );
			self::write_index_guard( $dir );
		}

		foreach ( $staged as $file ) {
			$source = self::norm( (string) ( $file['tmp_path'] ?? '' ) );
			$dest   = $ready ? $dir . '/' . $file['name'] : $source;
			$moved  = $ready && self::move_file( $source, $dest );

			if ( ! $moved ) {
				// 归档失败就留在暂存目录：邮件照样能带附件，只是后台给不出可点的链接
				$dest = $source;
			}

			// 相对 Web 路径：主站 80 与 WP 8081 两个域都能直接打开
			$web_path = ( $moved && str_starts_with( self::norm( $dest ), self::storage_base() ) )
				? self::storage_web_base() . substr( self::norm( $dest ), strlen( self::storage_base() ) )
				: '';

			$out[] = array(
				'name' => (string) $file['name'],
				'path' => self::norm( $dest ),
				'url'  => $web_path,
				'size' => (int) $file['size'],
			);
		}

		return $out;
	}

	/** 给可 Web 直接访问的目录补一个 index.php，避免目录列表暴露附件。 */
	private static function write_index_guard( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$file = rtrim( self::norm( $dir ), '/' ) . '/index.php';
		if ( ! file_exists( $file ) ) {
			@file_put_contents( $file, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * 把暂存文件挪进留档目录。
	 *
	 * 先 rename（同一文件系统下是原子的、零拷贝）；失败再 copy + unlink。
	 * 共享主机上 /tmp 与 uploads 经常是两个挂载点，rename 会直接 EXDEV 失败，
	 * 而失败后原本会静默退回暂存路径 —— 表现就是「邮件里有附件、后台附件列是空的」。
	 */
	private static function move_file( string $from, string $to ): bool {
		if ( $from === '' || ! is_file( $from ) ) {
			return false;
		}
		if ( @rename( $from, $to ) ) {
			return true;
		}
		if ( @copy( $from, $to ) ) {
			wp_delete_file( $from );
			return true;
		}

		return false;
	}

	/** 某条询盘的附件目录（CSPRNG 后缀，避免可猜测的直链） */
	private static function record_dir( int $record_id ): string {
		static $suffixes = array();
		if ( ! isset( $suffixes[ $record_id ] ) ) {
			$suffixes[ $record_id ] = strtolower( wp_generate_password( 12, false, false ) );
		}
		return self::storage_base() . gmdate( 'Ym' ) . '/' . $record_id . '-' . $suffixes[ $record_id ];
	}

	/**
	 * 删除询盘记录时，连同附件文件与目录一起清理。
	 *
	 * 两类文件都要管：
	 *   1. 已归档的（uploads/ifi-inquiries/{Ym}/{id-随机}/）；
	 *   2. 归档失败、退回进程暂存目录的（get_temp_dir()/ifi-stage-*）——这类文件不在
	 *      uploads 下，但 meta 里记着绝对路径，永久删除时同样必须清掉，否则删了记录、
	 *      文件还在磁盘上躺着。
	 *
	 * 只删这两处白名单内的路径：meta 是可写的，不能让一条构造过的记录把删除动作
	 * 变成任意文件删除。
	 */
	public static function purge_record_files( int $post_id ): void {
		if ( get_post_type( $post_id ) !== self::POST_TYPE ) {
			return;
		}
		$files = get_post_meta( $post_id, 'ifi_inquiry_attachments', true );
		$files = is_array( $files ) ? array_filter( $files, 'is_array' ) : array();
		$dirs  = array();

		$storage_root = realpath( rtrim( self::storage_base(), '/' ) );
		$storage_root = false === $storage_root ? '' : self::norm( $storage_root );
		$stage_root   = realpath( rtrim( self::norm( get_temp_dir() ), '/' ) );
		$stage_root   = false === $stage_root ? '' : self::norm( $stage_root );

		foreach ( $files as $file ) {
			$path = self::norm( (string) ( $file['path'] ?? '' ) );
			if ( '' === $path ) {
				continue;
			}

			// realpath() resolves ".." and symlinks: a crafted meta value that merely starts
			// with the storage prefix must not be able to delete a file outside it.
			$real = realpath( $path );
			if ( false === $real ) {
				continue;
			}
			$real = self::norm( $real );

			$in_storage = '' !== $storage_root && str_starts_with( $real, $storage_root . '/' );
			$in_stage   = '' !== $stage_root && str_starts_with( $real, $stage_root . '/ifi-stage-' );
			if ( ! $in_storage && ! $in_stage ) {
				continue;
			}

			wp_delete_file( $real );
			if ( $in_storage ) {
				$dirs[ dirname( $real ) ] = true;
			}
		}

		// meta 之外再兜一层：记录自己的目录名以 "{ID}-" 开头（record_dir() 的命名），
		// 即使 meta 缺项或旧数据没写全，也不会留下一只装着文件的空壳目录。
		foreach ( glob( self::storage_base() . '*/*', GLOB_ONLYDIR ) ?: array() as $dir ) {
			if ( 0 !== strpos( basename( $dir ), $post_id . '-' ) ) {
				continue;
			}
			$dirs[ self::norm( $dir ) ] = true;
			foreach ( glob( $dir . '/*' ) ?: array() as $file ) {
				if ( is_file( $file ) ) {
					wp_delete_file( $file );
				}
			}
		}

		foreach ( array_keys( $dirs ) as $dir ) {
			if ( is_dir( $dir ) ) {
				@rmdir( $dir ); // 清空了才删得掉；残留留待人工
			}
		}
	}

	/**
	 * 清空本次提交的暂存目录。
	 *
	 * @param array $keep 仍被引用的文件路径（挪进留档目录失败时退回暂存路径的），不删.
	 */
	private static function cleanup_stage( array $keep = array() ): void {
		$dir = self::stage_dir();
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$keep = array_map( array( __CLASS__, 'norm' ), $keep );
		foreach ( glob( $dir . '/*' ) ?: array() as $f ) {
			if ( in_array( self::norm( $f ), $keep, true ) ) {
				continue;
			}
			wp_delete_file( $f );
		}
		@rmdir( $dir ); // 还有保留文件时删不掉，留着给下次或人工处理
	}

	/**
	 * 限流用的请求标识（与邮件里的 {{ip}} 分开：那边如实记录服务端看到的地址）。
	 *
	 *   1. 默认只取 REMOTE_ADDR；只有配置的可信代理才采信 X-Forwarded-For / X-Real-IP；
	 *   2. 回环或私网地址退化成服务端签发的 HttpOnly cookie（带 HMAC 签名），每个访客一个桶。
	 *
	 * 注意：cookie 是"区分访客"用的，不是身份凭证；丢弃 cookie 的客户端
	 * 会拿到新标识，所以要拦住无状态刷接口还得靠链路层拿到真实 IP。
	 */
}

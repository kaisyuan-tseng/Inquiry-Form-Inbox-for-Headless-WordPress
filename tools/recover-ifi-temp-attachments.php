<?php
/**
 * 一次性修复：把旧版本留在「系统临时目录」里的询盘附件挪回 uploads，并补上可访问的 URL。
 *
 * 背景：2.0.3 之前，附件归档用的是裸 rename()。共享主机上 /tmp 与 wp-content/uploads
 * 常在不同挂载点，rename 会 EXDEV 失败，代码静默退回暂存路径 —— 文件留在
 * `{get_temp_dir()}ifi-stage-xxxx/` 下（3.0.0 之前叫 site-inq-xxxx），邮件带着它发出去了，但后台因为算不出 URL 显示「—」。
 *
 * 用法（SiteGround SSH，在 WordPress 根目录执行）：
 *   wp eval-file wp-content/plugins/inquiry-form-inbox/tools/recover-ifi-temp-attachments.php --dry-run
 *   wp eval-file wp-content/plugins/inquiry-form-inbox/tools/recover-ifi-temp-attachments.php
 *
 * 只处理「meta 里 url 为空」的旧记录；匹配依据是文件名 + 字节数，二者都对上才搬，
 * 拿不准的一律不动，只在清单里标出来。
 *
 * 本地调试：WP_ROOT=/path/to/wordpress php tools/recover-ifi-temp-attachments.php --dry-run
 */

if ( ! defined( 'ABSPATH' ) ) {
	$root = getenv( 'WP_ROOT' );
	if ( ! $root ) {
		fwrite( STDERR, "请用 wp eval-file 运行，或先设置 WP_ROOT 环境变量。\n" );
		exit( 1 );
	}
	$_SERVER['HTTP_HOST']      = $_SERVER['HTTP_HOST'] ?? 'localhost';
	$_SERVER['REQUEST_URI']    = '/';
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$_SERVER['SERVER_NAME']    = $_SERVER['SERVER_NAME'] ?? 'localhost';
	require rtrim( $root, '/\\' ) . '/wp-load.php';
}

$args = $args ?? array_slice( $argv ?? array(), 1 );
$args = (array) $args;
$dry  = in_array( '--dry-run', $args, true ) || in_array( 'dry-run', $args, true );

$type    = class_exists( 'Inquiry_Form_Inbox' ) ? Inquiry_Form_Inbox::POST_TYPE : 'ifi_inquiry';
$metaKey = 'ifi_inquiry_attachments';
$dirName = class_exists( 'Inquiry_Form_Inbox' ) ? Inquiry_Form_Inbox::STORAGE_DIR : 'ifi-inquiries';

$uploads = wp_get_upload_dir();
$base    = rtrim( str_replace( '\\', '/', $uploads['basedir'] ), '/' ) . '/' . $dirName . '/';
$webBase = rtrim( (string) parse_url( $uploads['baseurl'], PHP_URL_PATH ), '/' ) . '/' . $dirName . '/';

echo $dry ? "== 演练模式（不写文件、不改 meta）==\n\n" : "== 执行模式 ==\n\n";

/* ---------- 1) 找出所有「没有 URL」的历史附件 ---------- */
$posts = get_posts(
	array(
		'post_type'      => $type,
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);

$pending = array(); // basename|size => 记录与附件条目
foreach ( $posts as $post_id ) {
	$files = get_post_meta( $post_id, $metaKey, true );
	if ( ! is_array( $files ) ) {
		continue;
	}
	foreach ( $files as $index => $file ) {
		$url  = (string) ( $file['url'] ?? '' );
		$path = (string) ( $file['path'] ?? '' );
		$name = (string) ( $file['name'] ?? '' );
		if ( $url !== '' || $name === '' ) {
			continue;
		}
		$pending[ $name . '|' . (int) ( $file['size'] ?? 0 ) ][] = array(
			'post_id' => $post_id,
			'index'   => $index,
			'file'    => $file,
			'path'    => $path,
		);
	}
}

echo '历史记录里缺 URL 的附件条目：' . array_sum( array_map( 'count', $pending ) ) . " 个\n";

/* ---------- 2) 扫描临时目录里残留的暂存文件 ---------- */
$candidates = array();
foreach ( array_unique( array( rtrim( get_temp_dir(), '/\\' ), rtrim( sys_get_temp_dir(), '/\\' ) ) ) as $tmp ) {
	foreach ( (array) array_merge(
	(array) glob( $tmp . '/ifi-stage-*/*' ),
	(array) glob( $tmp . '/site-inq-*/*' ) // 3.0.0 之前暂存目录的旧名字，一并找
) as $file ) {
		if ( is_file( $file ) ) {
			$candidates[] = str_replace( '\\', '/', $file );
		}
	}
}
echo '临时目录里还在的暂存文件：' . count( $candidates ) . " 个（" . implode( ' , ', array_unique( array( rtrim( get_temp_dir(), '/\\' ), rtrim( sys_get_temp_dir(), '/\\' ) ) ) ) . "）\n\n";

if ( ! $candidates ) {
	echo "没有找到可恢复的暂存文件。旧附件可能已被主机清理（/tmp 不会被长期保留），\n";
	echo "只能靠当时那封通知邮件里的附件副本了。\n";
	return;
}

/* ---------- 3) 按 文件名 + 字节数 配对，搬到留档目录 ---------- */
$moved = 0;
$skipped = 0;

foreach ( $candidates as $source ) {
	$name = basename( $source );
	$size = (int) @filesize( $source );
	$key  = $name . '|' . $size;

	if ( empty( $pending[ $key ] ) ) {
		printf( "跳过  %-40s 没有匹配的询盘记录（或大小不一致）\n", $name );
		$skipped++;
		continue;
	}
	if ( count( $pending[ $key ] ) > 1 ) {
		printf( "跳过  %-40s 同名同大小命中 %d 条记录，需人工确认\n", $name, count( $pending[ $key ] ) );
		$skipped++;
		continue;
	}

	$hit     = $pending[ $key ][0];
	$post_id = (int) $hit['post_id'];
	$record  = $base . gmdate( 'Ym', strtotime( get_post_time( 'Y-m-d H:i:s', true, $post_id ) ) ) . '/' . $post_id . '-' . substr( md5( $source ), 0, 12 ) . '/';
	$dest    = $record . $name;

	if ( $dry ) {
		printf( "待搬  %-40s -> %s\n", $name, $dest );
		continue;
	}

	if ( ! wp_mkdir_p( $record ) ) {
		printf( "失败  %-40s 建目录失败：%s\n", $name, $record );
		$skipped++;
		continue;
	}
	if ( ! @copy( $source, $dest ) ) {
		printf( "失败  %-40s 复制失败\n", $name );
		$skipped++;
		continue;
	}
	wp_delete_file( $source );

	$files = get_post_meta( $post_id, $metaKey, true );
	$rel   = str_replace( '\\', '/', $dest );
	$files[ $hit['index'] ]['path'] = $rel;
	$files[ $hit['index'] ]['url']  = $webBase . substr( $rel, strlen( $base ) );
	update_post_meta( $post_id, $metaKey, $files );

	printf( "已恢复 %-40s -> %s\n", $name, $files[ $hit['index'] ]['url'] );
	$moved++;

	// 暂存目录清空了就一并删掉，别在 /tmp 里留一堆空壳
	$stage_dir = dirname( $source );
	if ( is_dir( $stage_dir ) ) {
		@rmdir( $stage_dir ); // 非空时失败，正是想要的行为
	}
}

echo "\n完成：恢复 {$moved} 个，跳过 {$skipped} 个";
echo $dry ? "（演练，未改动任何文件）\n" : "。后台刷新询盘详情即可看到附件链接。\n";

<?php
/**
 * IFI_Manifest_Trait — Inquiry Form Inbox for Headless WordPress.
 */

defined( 'ABSPATH' ) || exit;

trait IFI_Manifest_Trait {

	private static function frontend_field_manifest(): array {
		$manifest = apply_filters( 'ifi_field_manifest', array() );
		if ( ! is_array( $manifest ) ) {
			return array();
		}
		$keys = array();
		foreach ( $manifest as $key ) {
			if ( is_string( $key ) && $key !== '' ) {
				$keys[] = $key;
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * 字段标签表：**完全由后台手动添加**，键名要和前端表单的字段名一致。
	 *
	 * 不再从历史询盘/前端清单自动收集——有哪些字段、行序如何，全看这里保存的
	 * labels 键序（用户拖过就是它）；默认标签只做字段名格式化
	 * （outerDiameter → Outer Diameter），不取前端任何语言的文案。
	 *
	 * @param array|null $preferred 当前模板保存的 labels，其键序决定列表顺序.
	 */
	private static function label_fields( ?array $preferred = null ): array {
		$preferred = $preferred ?? (array) ( self::tpl( 'notify' )['labels'] ?? array() );

		$fields = array();
		foreach ( array_keys( $preferred ) as $field_key ) {
			$field_key = (string) $field_key;
			if ( $field_key !== '' ) {
				$fields[ $field_key ] = self::readable_label( $field_key );
			}
		}

		return array(
			'fields' => $fields,
			'sample' => ! $fields, // 一条标签都没有 = 空态
		);
	}

	/* ================================================================== 启动 */
}

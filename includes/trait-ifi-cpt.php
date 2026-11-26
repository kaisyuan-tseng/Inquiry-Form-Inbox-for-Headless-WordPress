<?php
/**
 * IFI_CPT_Trait — Inquiry Form Inbox for Headless WordPress.
 *
 * 询盘列表：列结构、视图（全部 / 新询盘 / 已联系 / 回收站）、来源筛选与排序。
 */

defined( 'ABSPATH' ) || exit;

trait IFI_CPT_Trait {


	/** 后台列表页地址（顶层菜单：admin.php?page=ifi-inquiry） */
	public static function list_url(): string {
		return admin_url( 'admin.php?page=' . self::LIST_SLUG );
	}

	/**
	 * 顶层菜单「询盘」：列表页自己渲染，邮件模板做它下面的子菜单。
	 *
	 * CPT 设 show_in_menu=false，列表不再走 edit.php?post_type=ifi_inquiry。
	 */
	public static function register_list_page(): void {
		add_menu_page(
			self::t( 'menu_name' ),
			self::t( 'menu_name' ),
			Inquiry_Form_Inbox::CAP_VIEW,
			self::LIST_SLUG,
			array( __CLASS__, 'render_list_page' ),
			'dashicons-email',
			26
		);
		// add_menu_page 会自动加一条同名子菜单：菜单文案改成「所有询盘」避免重复，
		// 但页面标题保持 Inquiries —— get_admin_page_title() 取的是子菜单第一项
		add_submenu_page(
			self::LIST_SLUG,
			self::t( 'menu_name' ),
			self::t( 'all_items' ),
			Inquiry_Form_Inbox::CAP_VIEW,
			self::LIST_SLUG,
			array( __CLASS__, 'render_list_page' )
		);
	}

	/**
	 * 自渲染列表时把 screen 伪装成原生 edit-ifi_inquiry，
	 * 让列 / 视图 / 来源筛选 / 看板 / 样式这些既有钩子照常生效。
	 */
	public static function list_screen( $screen ) {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! $screen || ! isset( $_GET['page'] ) ) {
			return $screen;
		}
		if ( sanitize_key( (string) $_GET['page'] ) !== self::LIST_SLUG ) {
			return $screen;
		}
        // phpcs:enable
		$screen->id        = 'edit-' . self::POST_TYPE;
		$screen->base      = 'edit';
		$screen->post_type = self::POST_TYPE;
		$screen->taxonomy  = '';
		return $screen;
	}

	/** 列表页：核心列表表格 + 工具栏（与 edit.php 用同一套 hooks） */
	public static function render_list_page(): void {
		if ( ! current_user_can( Inquiry_Form_Inbox::CAP_VIEW ) ) {
			wp_die( esc_html( self::t( 'error_forbidden' ) ), '', array( 'response' => 403 ) );
		}
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-posts-list-table.php';

		// wp_edit_posts_query() 读 $_GET['post_type']，不给就退回 'post'，会把普通文章列出来
		global $typenow, $post_type, $post_type_object;
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- 原生列表表格依赖这几个全局量
		$typenow          = self::POST_TYPE;
		$post_type        = self::POST_TYPE;
		$post_type_object = get_post_type_object( self::POST_TYPE );
        // phpcs:enable
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 只是把自家 CPT 塞进查询
		$_GET['post_type']     = self::POST_TYPE;
		$_REQUEST['post_type'] = self::POST_TYPE;
        // phpcs:enable

		// 不传 'screen' 参数：WP_List_Table 会走 get_current_screen()，拿到的就是
		// list_screen() 已经改写成 edit-ifi_inquiry 的那个对象。传入字符串
		// 'edit-ifi_inquiry' 会让 convert_to_screen() 去查一个并不存在的 hook，
		// 返回 false，于是 get_column_headers() 拿不到列 —— 表头是空的。
		/*
		 * 显式传 WP_Screen 对象，不传字符串、也不依赖构造函数自己推断。
		 *
		 * - 传字符串 'edit-ifi_inquiry'：convert_to_screen() 会去查一个并不存在的 hook，
		 *   返回 false，列解析随即落空（表头与每个单元格都是空的）。
		 * - 不传：构造函数走 get_current_screen()，在 WP 7.1 上同样可能拿到 false。
		 *
		 * 这里传的对象就是 list_screen() 已经改写好的那一个，id 恒为 edit-ifi_inquiry，
		 * get_column_headers() 才能命中 admin_columns 注册的过滤器。
		 */
		$list_table = new WP_Posts_List_Table( array( 'screen' => 'edit-' . self::POST_TYPE ) );
		$list_table->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html( self::t( 'menu_name' ) ); ?></h1>
			<hr class="wp-header-end" />
			<?php $list_table->views(); ?>
			<form id="posts-filter" method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::LIST_SLUG ); ?>" />
				<input type="hidden" name="post_type" value="<?php echo esc_attr( self::POST_TYPE ); ?>" />
				<?php $list_table->search_box( self::t( 'search_items' ), 'ifi-inquiry-search' ); ?>
				<?php $list_table->display(); ?>
			</form>
		</div>
		<?php
	}

	/** 询盘留档 CPT：不公开、仅后台可见、UI 禁止新建（wp_insert_post 不查 caps） */
	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => self::t( 'menu_name' ),
					'singular_name'      => self::t( 'menu_name' ),
					'menu_name'          => self::t( 'menu_name' ),
					'all_items'          => self::t( 'all_items' ),
					'search_items'       => self::t( 'search_items' ),
					'not_found'          => self::t( 'not_found' ),
					'not_found_in_trash' => self::t( 'not_found_trash' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				// 顶层菜单自己渲染列表，URL 用 admin.php?page=ifi-inquiry
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'menu_icon'           => 'dashicons-email',
				'menu_position'       => 26,
				'supports'            => array( 'title', 'editor' ),
				'capability_type'     => array( 'ifi_inquiry', 'ifi_inquiries' ),
				'capabilities'        => array(
					'create_posts'           => 'do_not_allow',
					'edit_posts'             => Inquiry_Form_Inbox::CAP_EDIT,
					'edit_others_posts'      => Inquiry_Form_Inbox::CAP_EDIT,
					'edit_published_posts'   => Inquiry_Form_Inbox::CAP_EDIT,
					'edit_private_posts'     => Inquiry_Form_Inbox::CAP_EDIT,
					'publish_posts'          => Inquiry_Form_Inbox::CAP_EDIT,
					'read'                   => Inquiry_Form_Inbox::CAP_VIEW,
					'read_private_posts'     => Inquiry_Form_Inbox::CAP_VIEW,
					'delete_posts'           => Inquiry_Form_Inbox::CAP_DELETE,
					'delete_private_posts'   => Inquiry_Form_Inbox::CAP_DELETE,
					'delete_published_posts' => Inquiry_Form_Inbox::CAP_DELETE,
					'delete_others_posts'    => Inquiry_Form_Inbox::CAP_DELETE,
				),
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * 列表页视图：全部 / 待处理 / 已联系 / 垃圾 / 回收站。
	 *
	 * 「待处理」= 未标记联系的真实询盘；「垃圾」= 被规则拦截（蜜罐等）的记录；
	 * 两者都排除在「全部」之外，回收站独立计数。
	 */
	public static function admin_views( array $views ): array {
		$counts    = wp_count_posts( self::POST_TYPE );
		$publish   = (int) ( $counts->publish ?? 0 );
		$trash     = (int) ( $counts->trash ?? 0 );
		$spam      = self::spam_count();
		$contacted = self::contacted_count();
		$pending   = max( 0, $publish - $spam - $contacted );
		$current   = self::status_view();

		$base = self::list_url();

		return array(
			'all'           => self::view_link( $base, self::t( 'view_all' ), max( 0, $publish - $spam ), $current === '' ),
			'ifi_new'       => self::view_link(
				add_query_arg( 'ifi_status', 'pending', $base ),
				self::t( 'view_pending' ),
				$pending,
				$current === 'pending'
			),
			'ifi_contacted' => self::view_link(
				add_query_arg( 'ifi_status', 'contacted', $base ),
				self::t( 'view_contacted' ),
				$contacted,
				$current === 'contacted'
			),
			'ifi_spam'      => self::view_link(
				add_query_arg( 'ifi_status', 'spam', $base ),
				self::t( 'view_spam' ),
				$spam,
				$current === 'spam'
			),
			'trash'         => self::view_link(
				add_query_arg( 'post_status', 'trash', $base ),
				self::t( 'view_trash' ),
				$trash,
				$current === 'trash'
			),
		);
	}

	/** 单个视图链接（结构与核心一致：a > 文案 + span.count） */
	private static function view_link( string $url, string $label, int $count, bool $active ): string {
		return sprintf(
			'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
			esc_url( $url ),
			$active ? ' class="current" aria-current="page"' : '',
			esc_html( $label ),
			esc_html( number_format_i18n( $count ) )
		);
	}

	/** 当前停在哪个视图：'' | pending | contacted | spam | trash */
	private static function status_view(): string {
        // phpcs:disable WordPress.Security.NonceVerification
		$post_status = isset( $_GET['post_status'] ) ? sanitize_key( (string) $_GET['post_status'] ) : '';
		if ( $post_status === 'trash' ) {
			return 'trash';
		}
		$view = isset( $_GET['ifi_status'] ) ? sanitize_key( (string) $_GET['ifi_status'] ) : '';
        // phpcs:enable

		if ( $view === 'new' ) {
			$view = 'pending';
		}
		return in_array( $view, array( 'pending', 'contacted', 'spam' ), true ) ? $view : '';
	}

	/** 已联系（有 ifi_inquiry_contacted、且不是垃圾）的已留档询盘数 */
	private static function contacted_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
                 WHERE p.post_type = %s AND p.post_status = 'publish'
                   AND NOT EXISTS (
                       SELECT 1 FROM {$wpdb->postmeta} sp
                       WHERE sp.post_id = p.ID AND sp.meta_key = 'ifi_inquiry_spam'
                   )",
				'ifi_inquiry_contacted',
				self::POST_TYPE
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- 视图计数，按 meta 精确统计。
	}

	/** 被规则拦截的垃圾询盘数（不含回收站） */
	public static function spam_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'ifi_inquiry_spam'
                 WHERE p.post_type = %s AND p.post_status = 'publish'",
				self::POST_TYPE
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- 视图计数。
	}

	/** 待处理（真实、未联系）的询盘数 */
	public static function pending_count(): int {
		$counts  = wp_count_posts( self::POST_TYPE );
		$publish = (int) ( $counts->publish ?? 0 );
		return max( 0, $publish - self::spam_count() - self::contacted_count() );
	}

	/**
	 * 批量操作：列表页只留「移动至回收站」。
	 * 回收站视图里换成 WordPress 自带的一组（恢复 / 永久删除），否则回收站进去就出不来。
	 */
	public static function admin_bulk_actions( array $actions ): array {
        // phpcs:ignore WordPress.Security.NonceVerification
		$in_trash = isset( $_GET['post_status'] ) && $_GET['post_status'] === 'trash';

		if ( $in_trash ) {
			$keep = array();
			foreach ( array( 'untrash', 'delete' ) as $key ) {
				if ( isset( $actions[ $key ] ) ) {
					$keep[ $key ] = $actions[ $key ];
				}
			}
			return $keep ?: $actions;
		}

		return isset( $actions['trash'] ) ? array( 'trash' => $actions['trash'] ) : array();
	}

	/** 列表上方的「来源」筛选下拉：选项来自已存档的英文页面标题 */
	public static function source_filter( string $post_type ): void {
		if ( $post_type !== self::POST_TYPE ) {
			return;
		}
		$values = self::source_values();
		if ( ! $values ) {
			return;
		}
        // phpcs:ignore WordPress.Security.NonceVerification
		$current = isset( $_GET['ifi_inquiry_source'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['ifi_inquiry_source'] ) ) : '';

		echo '<select name="ifi_inquiry_source" style="max-width:260px;" onchange="this.form.submit()">';
		echo '<option value="">' . esc_html( self::t( 'all_sources' ) ) . '</option>';
		foreach ( $values as $value ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $value, $current, false ),
				esc_html( self::clean_source_title( $value ) )
			);
		}
		echo '</select>';
	}

	/** 已存档记录里出现过的来源（英文标题），按字母序 */
	private static function source_values(): array {
		global $wpdb;
		$rows = $wpdb->get_col(
			"SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = 'ifi_inquiry_page_title_en' AND meta_value <> ''
             ORDER BY meta_value ASC"
		);
		return array_values( array_filter( array_map( 'trim', (array) $rows ), static fn( $v ) => $v !== '' ) );
	}

	/** 编号 / 接收时间排序 + 来源筛选 + 新询盘 / 已联系视图（只在询盘列表的主查询上生效） */
	public static function apply_list_filters( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( $query->get( 'post_type' ) !== self::POST_TYPE ) {
			return;
		}

        // phpcs:disable WordPress.Security.NonceVerification
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( (string) $_GET['orderby'] ) : '';
		$order   = isset( $_GET['order'] ) && strtoupper( sanitize_text_field( wp_unslash( (string) $_GET['order'] ) ) ) === 'ASC' ? 'ASC' : 'DESC';
		$source  = isset( $_GET['ifi_inquiry_source'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['ifi_inquiry_source'] ) ) : '';
        // phpcs:enable

		// 编号排序：IQ-YYYYMMDDXXX 前缀恒定、后缀定宽补零，字符串序即时间序。
		// （历史记录里的纯数字编号排在 IQ- 之前，与先后顺序一致；数值序对带前缀的串会全变 0，不能用）
		if ( $orderby === 'ifi_inquiry_no' ) {
			$query->set( 'meta_key', 'ifi_inquiry_no' );
			$query->set( 'orderby', 'meta_value' );
			$query->set( 'order', $order );
		} elseif ( $orderby === 'ifi_inquiry_received' ) {
			// 「接收时间」就是 post_date，交给核心按日期排序
			$query->set( 'orderby', 'date' );
			$query->set( 'order', $order );
		}

		$meta_query = array();

		// 垃圾记录只在 Spam 视图出现；其它视图（含回收站）一律排除
		$view = self::status_view();
		if ( $view === 'spam' ) {
			$meta_query[] = array(
				'key'     => 'ifi_inquiry_spam',
				'compare' => 'EXISTS',
			);
		} elseif ( $view !== 'trash' ) {
			$meta_query[] = array(
				'key'     => 'ifi_inquiry_spam',
				'compare' => 'NOT EXISTS',
			);

			// 待处理 / 已联系：以 ifi_inquiry_contacted 存在与否区分
			if ( $view === 'pending' ) {
				$meta_query[] = array(
					'key'     => 'ifi_inquiry_contacted',
					'compare' => 'NOT EXISTS',
				);
			} elseif ( $view === 'contacted' ) {
				$meta_query[] = array(
					'key'     => 'ifi_inquiry_contacted',
					'compare' => 'EXISTS',
				);
			}
		}

		// 搜索时不再叠加来源的精确 meta 过滤：来源下拉与搜索框在同一个 GET 表单里，
		// 选过来源后搜索会被悄悄收窄，看起来像搜索坏了。
		if ( $source !== '' && $query->get( 's' ) === '' ) {
			$meta_query[] = array(
				'key'     => 'ifi_inquiry_page_title_en',
				'value'   => $source,
				'compare' => '=',
			);
		}

		if ( $meta_query ) {
			if ( count( $meta_query ) > 1 ) {
				$meta_query['relation'] = 'AND';
			}
			$query->set( 'meta_query', $meta_query );
		}
	}

	/** 列表列宽：来源是「英文标题（表单类型）」，默认宽度会折成两行 */
	public static function admin_list_styles(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->id !== 'edit-' . self::POST_TYPE ) {
			return;
		}
		self::enqueue_admin_assets();
	}

	/** 主列固定为「编号」：行操作（查看 / 标记已联系 / 删除）就挂在编号下面 */
	public static function primary_column( string $column, string $screen_id ): string {
		return $screen_id === 'edit-' . self::POST_TYPE ? 'ifi_inquiry_no' : $column;
	}

	/** 可排序的列表列：编号按 ifi_inquiry_no 的字符串序，接收时间按 post_date */
	public static function sortable_columns( array $columns ): array {
		$columns['ifi_inquiry_no']       = 'ifi_inquiry_no';
		$columns['ifi_inquiry_received'] = 'ifi_inquiry_received';
		return $columns;
	}

	/**
	 * 列表列：编号 / 来自（姓名 + 邮箱）/ 内容摘要 / 附件 / 来源 / 自动发信状态 / 接收时间。
	 *
	 * **这里从零构建整份列表，不是在传入的 $columns 上改写。**
	 *
	 * WP_Posts_List_Table 会在构造函数里把「自己的 get_columns」挂到
	 * `manage_{$screen->id}_columns` 的优先级 0，再由本过滤器在 10 处接手改写。
	 * 但自渲染列表页的页面钩子是 toplevel_page_ifi-inquiry，不是 edit.php，
	 * convert_to_screen() 解析不出 edit-ifi_inquiry 屏幕，那个优先级 0 的提供者
	 * 因此根本不会注册 —— 传进来的 $columns 是空数组。
	 *
	 * 旧实现只在看到 'title' 键时才开始拼列，于是在自渲染页上产出了一份空列定义：
	 * 表头没有 th，每一行也没有 td，列表看起来「有 4 条记录但一片空白」。
	 * 从零构建之后，两条渲染路径（edit.php 与自渲染页）都能得到同一套列。
	 */
	public static function admin_columns( array $columns ): array {
		$new = array(
			'cb'                      => $columns['cb'] ?? '<input type="checkbox" />',
			'ifi_inquiry_no'          => self::t( 'col_no' ),
			'ifi_inquiry_from'        => self::t( 'col_from' ),
			'ifi_inquiry_message'     => self::t( 'col_message' ),
			'ifi_inquiry_attachments' => self::t( 'col_attachments' ),
			'ifi_inquiry_source'      => self::t( 'col_source' ),
			'ifi_inquiry_status'      => self::t( 'col_status' ),
			'ifi_inquiry_received'    => self::t( 'col_received' ),
		);

		// 别家插件加的列原样保留；自带的 title / date 由上面这组取代。
		foreach ( $columns as $key => $label ) {
			if ( in_array( $key, array( 'cb', 'title', 'date' ), true ) || isset( $new[ $key ] ) ) {
				continue;
			}
			$new[ $key ] = $label;
		}

		return $new;
	}

	public static function admin_column_content( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'ifi_inquiry_no':
				$no = (string) get_post_meta( $post_id, 'ifi_inquiry_no', true );
				echo $no !== ''
					? '<span class="ifi-no">' . esc_html( $no ) . '</span>'
					: '—';
				break;
			case 'ifi_inquiry_from':
				$email = (string) get_post_meta( $post_id, 'ifi_inquiry_email', true );
				echo '<strong>' . esc_html( get_the_title( $post_id ) ) . '</strong>';
				if ( $email !== '' ) {
					echo '<br><span class="ifi-muted">' . esc_html( $email ) . '</span>';
				}
				break;
			case 'ifi_inquiry_message':
				$values = (array) get_post_meta( $post_id, 'ifi_inquiry_field_values', true );
				$text   = trim( (string) ( $values['message'] ?? '' ) );
				// 垃圾记录只留最小集、没有字段值，正文常为空：先标出命中的规则，再决定要不要摘要
				$spam = (string) get_post_meta( $post_id, 'ifi_inquiry_spam', true );
				if ( $spam !== '' ) {
					echo '<span class="ifi-spam-tag">' . esc_html( self::spam_reason_label( $spam ) ) . '</span>';
				}
				if ( $text === '' ) {
					if ( $spam === '' ) {
						echo '—';
					}
					break;
				}
				if ( $spam !== '' ) {
					echo '<br />';
				}
				$excerpt = mb_strlen( $text ) > 80 ? mb_substr( $text, 0, 80 ) . '…' : $text;
				echo '<span title="' . esc_attr( $text ) . '">' . esc_html( $excerpt ) . '</span>';
				break;
			case 'ifi_inquiry_source':
				// 页面标题 + 表单类型（页面表单 / 弹窗表单），便于直接区分提交入口
				echo esc_html( self::record_source_label( $post_id, true ) );
				break;
			case 'ifi_inquiry_attachments':
				$files = self::record_attachments( $post_id );
				$count = count( $files );
				if ( $count === 0 ) {
					echo '—';
					break;
				}

				$label = $count === 1 ? self::t( 'file_one' ) : sprintf( self::t( 'files_many' ), $count );
				echo '<details class="ifi-attach">';
				echo '<summary>'
					. '<span class="dashicons dashicons-paperclip" aria-hidden="true"></span>'
					. '<span>' . esc_html( $label ) . '</span>'
					. '</summary>';
				echo '<ul class="ifi-attach__menu">';
				foreach ( $files as $file ) {
					$url  = (string) ( $file['url'] ?? '' );
					$name = (string) ( $file['name'] ?? '' );
					$size = self::size_label( (int) ( $file['size'] ?? 0 ) );

					echo '<li>';
					if ( $url !== '' ) {
						echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">'
							. '<span class="ifi-attach__name">' . esc_html( $name ) . '</span>'
							. '<span class="ifi-attach__size">' . esc_html( $size ) . '</span>'
							. '</a>';
					} else {
						echo '<span class="ifi-attach__row">'
							. '<span class="ifi-attach__name">' . esc_html( $name ) . '</span>'
							. '<span class="ifi-attach__size">' . esc_html( $size ) . '</span>'
							. '</span>';
					}
					echo '</li>';
				}
				echo '</ul></details>';
				break;
			case 'ifi_inquiry_status':
				echo self::mail_status_html( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML built and escaped by mail_status_html().
				break;
			case 'ifi_inquiry_received':
				echo '<span title="' . esc_attr( (string) get_the_date( self::datetime_format(), $post_id ) ) . '">'
					. esc_html( self::received_label( $post_id ) ) . '</span>';
				break;
		}
	}

	/**
	 * 「接收时间」列的紧凑写法：今天 / 昨天带时刻，其余按站点日期格式显示；
	 * 完整时间放在 title 里。
	 *
	 * 时刻与日期都读站点的 `time_format` / `date_format`（设置 → 常规）：
	 * 本站点选 24 小时制就显示 14:05，选 12 小时制就显示 2:05 pm，不再由插件替站长决定。
	 */
	private static function received_label( int $post_id ): string {
		$timestamp = (int) get_post_time( 'U', true, $post_id );
		if ( $timestamp <= 0 ) {
			return '—';
		}

		$time  = (string) wp_date( self::time_format(), $timestamp );
		$day   = wp_date( 'Y-m-d', $timestamp );
		$today = current_time( 'Y-m-d' );
		$ytd   = current_datetime()->modify( '-1 day' )->format( 'Y-m-d' );

		if ( $day === $today ) {
			return sprintf( self::t( 'dash_today_at' ), $time );
		}
		if ( $day === $ytd ) {
			return sprintf( self::t( 'dash_yesterday_at' ), $time );
		}
		return wp_date( self::date_format(), $timestamp ) . ' · ' . $time;
	}
}

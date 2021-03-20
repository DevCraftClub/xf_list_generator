<?php
/**
 * @author: Maxim Harder
 * @link: https://devcraft.club
 * @description: Автоматический генератор списков для вывода доп. полей
 * @version: 1.1.0
 *
 */


if (!defined('DATALIFEENGINE')) {
	die('see ya.');
}

if( empty($template) && empty($xffield)) return false;

include ('engine/api/api.class.php');
$dle_api = new DLE_API;
$where = ["p.xfields LIKE '%{$xffield}|%'"];

$limit = (isset($limit) && (int)$limit && (int) $limit !== 0) ? (int)$limit : '18446744073709551615';
$skip = (isset($skip) && (int)$skip && (int) $skip !== 0) ? (int)($skip+1) : 1;
$cprefix = "xf_gen_".$xffield;
$db_prefix = PREFIX;
$sort = (isset($sort) && in_array($sort, ['asc', 'desc', 'ASC', 'DESC'])) ? $sort : 'ASC';

if (isset($cat)) {
	$trust = true;

	foreach(explode(',', $cat) as $c) {
		if (!is_int((int)$c)) {
			$trust = false;
			break;
		}
	}

	if($trust) {
		if ($config['allow_multi_category']) {
			$where[] = "p.category IN (SELECT DISTINCT({$db_prefix}_post_extras_cats.cat_id)
                        FROM {$db_prefix}_post_extras_cats
                        WHERE cat_id IN ('{$cat}'))";
		} else {
			$where[] = "p.category IN ('{$cat}') ";
		}
	}
}
if (isset($news_id)) {
	$trust = true;

	foreach(explode(',', $news_id) as $n) {
		if (!is_int($n)) {
			$trust = false;
			break;
		}
	}

	if($trust) $where[] = "news_id IN ('{$news_id}') ";
}
$where = implode(" AND ", $where);
$xf_val_list = "SUBSTRING_INDEX( SUBSTRING_INDEX( p.xfields , '{$xffield}|', -1 ) , '||', 1 )";
$sql_field = "SELECT TRIM(xf) as x, COUNT(1) AS total FROM (
	SELECT id AS news_id, category AS cat_id, SUBSTRING_INDEX(
						SUBSTRING_INDEX(
							{$xf_val_list},
							',',
							d.digit
						),
						',',
						-1
					) as xf
	FROM {$db_prefix}_post p
	JOIN (SELECT 0 digit UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3  UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6) d
	ON CHAR_LENGTH({$xf_val_list})
	WHERE {$where}
) xf_in_rows

GROUP BY x
ORDER BY x {$sort}
LIMIT {$limit}
OFFSET {$skip}";
$cache_name = str_replace(' ', '_', "{$cprefix}_{$template}_{$limit}_{$skip}_{$sort}_{$cat}");

$gen = $dle_api->load_from_cache ( $cache_name );

if (!function_exists('get_min_max')) {
	function get_min_max(array $values, string $type)
	{
		if ('min' == $type) {
			return min(array_diff(array_map('intval', $values), [0]));
		}

		return max(array_diff(array_map('intval', $values), [0]));
	}
}

if (!function_exists('gen_xfields')) {
	function gen_xfields()
	{
		global $config;
		$xf_list = [];

		$handle = @fopen(ENGINE_DIR.'/data/xfields.txt', 'r');
		if ($handle) {
			while (($buffer = fgets($handle, 4096)) !== false) {
				$buf_split = explode('|', $buffer);
				$xf_list[$buf_split[0]] = [
					'name' => $buf_split[0],
					'value' => $buf_split[1],
					'type' => $buf_split[3],
					'splitter' => empty($buf_split[21]) ? ', ' : $buf_split[21],
					'search' => (1 === $buf_split[6]) ? true : false,
					'default' => [],
				];

				if ('select' == $buf_split[3]) {
					$default_values = explode('__NEWL__', $buf_split[4]);
					foreach ($default_values as $val) {
						$vals = explode('&#124;', $val);
						if(!isset($vals[1]) || empty($vals[1])) $vals[1] = $vals[0];
						$xf_list[$buf_split[0]]['default'][$vals[0]] = $vals[1];
					}
				}
			}
			fclose($handle);
		}

		return $xf_list;
	}
}

if( $gen ) {
	echo $gen;
} else {


	$db->query( $sql_field );

	$gen_tpl = new dle_template();
	$gen_tpl->dir = TEMPLATE_DIR;
	$gen_tpl->load_template( $template );
	$xffields = gen_xfields();
	$xf_chosen = $xffields[$xffield];
	$all_xfs = array();
	$min_max_value = [];

	$l = 0;
	$total = $db->num_rows();
	while($gen = $db->get_row()) {
		$l++;
		$xf = $gen['x'];
			if (!in_array($xf, $all_xfs)) {
				if ('select' == $xf_chosen['type']) {
					$value = $xffields[$xffield]['default'][$xf];
				} else {
					$value = $xf;
				}

				$link = '';

				if ($xffields[$xffield]['search']) {
					$url_val = str_replace(['&#039;', '&quot;', '&amp;', '&#123;', '&#91;', '&#58;'], ["'", '"', '&', '{', '[', ':'], $value);

					if ($config['allow_alt_url']) {
						$link = '<a href="'.$config['http_home_url'].'xfsearch/'.$xffield.'/'.rawurlencode($url_val).'/">'.$value.'</a>';
					} else {
						$link = "<a href=\"{$PHP_SELF}?do=xfsearch&amp;xfname=".$xffield.'&amp;xf='.rawurlencode($url_val).'">'.$value.'</a>';
					}
				}

				if ((int) $xf) {
					$min_max_value[] = (int) $xf;
				}

				$gen_tpl->set('{link}', $link);
				$gen_tpl->set('{name}', $value);
				$gen_tpl->set('{value}', $xf);
				$gen_tpl->set('{count}', ((int) $gen['total']/6));

				$all_xfs[] = $xf;
			} else {
				$gen_tpl->set('{link}', '');
				$gen_tpl->set('{name}', '');
				$gen_tpl->set('{value}', '');
				$gen_tpl->set('{count}', '');
			}

			if ($l == $total) {
				$gen_tpl->set('{max_value}', get_min_max($min_max_value, 'max'));
				$gen_tpl->set('{min_value}', get_min_max($min_max_value, 'min'));
			} else {
				$gen_tpl->set('{max_value}', '');
				$gen_tpl->set('{min_value}', '');
			}

			$compile = false;
			foreach ($gen_tpl->data as $n => $v) {
				if (!empty($v)) {
					$compile = true;
				}
			}
			if ($compile) {
				$gen_tpl->compile('gen_item');
			}

	}

	echo $gen_tpl->result['gen_item'];

	$dle_api->save_to_cache (  $cache_name, $gen_tpl->result['gen_item'] );
	$gen_tpl->clear();
	unset($gen_tpl);
	$db->free();


}


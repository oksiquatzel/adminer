<?php
$TABLE = $_GET["select"];
$table_status = table_status($TABLE);
$indexes = indexes($TABLE);
$fields = fields($TABLE);
$foreign_keys = column_foreign_keys($TABLE);

$rights = array(); // privilege => 0
$columns = array(); // selectable columns
unset($text_length);
foreach ($fields as $key => $field) {
	$name = $adminer->fieldName($field);
	if (isset($field["privileges"]["select"]) && strlen($name)) {
		$columns[$key] = html_entity_decode(strip_tags($name));
		if (ereg('text|blob', $field["type"])) {
			$text_length = $adminer->selectLengthProcess();
		}
	}
	$rights += $field["privileges"];
}

list($select, $group) = $adminer->selectColumnsProcess($columns, $indexes);
$where = $adminer->selectSearchProcess($fields, $indexes);
$order = $adminer->selectOrderProcess($fields, $indexes);
$limit = $adminer->selectLimitProcess();
$from = ($select ? implode(", ", $select) : "*") . " FROM " . idf_escape($TABLE) . ($where ? " WHERE " . implode(" AND ", $where) : "");
$group_by = ($group && count($group) < count($select) ? " GROUP BY " . implode(", ", $group) : "") . ($order ? " ORDER BY " . implode(", ", $order) : "");

if ($_POST && !$error) {
	$where_check = "(" . implode(") OR (", array_map('where_check', (array) $_POST["check"])) . ")";
	$primary = ($indexes["PRIMARY"] ? ($select ? array_flip($indexes["PRIMARY"]["columns"]) : array()) : null); // empty array means that all primary fields are selected
	foreach ($select as $key => $val) {
		$val = $_GET["columns"][$key];
		if (!$val["fun"]) {
			unset($primary[$val["col"]]);
		}
	}
	if ($_POST["export"]) {
		dump_headers($TABLE);
		dump_table($TABLE, "");
		if ($_POST["format"] != "sql") { // Editor doesn't send format
			dump_csv($select ? $select : array_keys($fields));
		}
		if (!is_array($_POST["check"]) || $primary === array()) {
			dump_data($TABLE, "INSERT", "SELECT $from" . (is_array($_POST["check"]) ? ($where ? " AND " : " WHERE ") . "($where_check)" : "") . $group_by);
		} else {
			$union = array();
			foreach ($_POST["check"] as $val) {
				// where is not unique so OR can't be used
				$union[] = "(SELECT $from " . ($where ? "AND " : "WHERE ") . where_check($val) . $group_by . " LIMIT 1)";
			}
			dump_data($TABLE, "INSERT", implode(" UNION ALL ", $union));
		}
		exit;
	}
	if (!$adminer->selectEmailProcess($where, $foreign_keys)) {
		if (!$_POST["import"]) { // edit
			$result = true;
			$affected = 0;
			$command = ($_POST["delete"] ? "DELETE FROM " : ($_POST["clone"] ? "INSERT INTO " : "UPDATE ")) . idf_escape($TABLE);
			$set = array();
			if (!$_POST["delete"]) {
				foreach ($columns as $name => $val) { //! should check also for edit or insert privileges
					$val = process_input($fields[$name]);
					if ($_POST["clone"]) {
						$set[idf_escape($name)] = ($val !== false ? $val : idf_escape($name));
					} elseif ($val !== false) {
						$set[] = idf_escape($name) . " = $val";
					}
				}
				$command .= ($_POST["clone"] ? " (" . implode(", ", array_keys($set)) . ")\nSELECT " . implode(", ", $set) . "\nFROM " . idf_escape($TABLE) : " SET\n" . implode(",\n", $set));
			}
			if ($_POST["delete"] || $set) {
				if ($_POST["all"] || ($primary === array() && $_POST["check"])) {
					$result = queries($command . ($_POST["all"] ? ($where ? "\nWHERE " . implode(" AND ", $where) : "") : "\nWHERE $where_check"));
					$affected = $connection->affected_rows;
				} else {
					foreach ((array) $_POST["check"] as $val) {
						// where is not unique so OR can't be used
						$result = queries($command . "\nWHERE " . where_check($val) . (count($group) < count($select) ? "" : "\nLIMIT 1"));
						if (!$result) {
							break;
						}
						$affected += $connection->affected_rows;
					}
				}
			}
			queries_redirect(remove_from_uri("page"), lang('%d item(s) have been affected.', $affected), $result);
			//! display edit page in case of an error
		} elseif (is_string($file = get_file("csv_file", true))) {
			$file = preg_replace("~^\xEF\xBB\xBF~", '', $file); //! character set
			$result = true;
			$cols = array_keys($fields);
			preg_match_all('~("[^"]*"|[^"\\r\\n])+~', $file, $matches);
			$affected = count($matches[0]);
			queries("START TRANSACTION");
			foreach ($matches[0] as $key => $val) {
				preg_match_all('~(("[^"]*")+|[^,]*),~', "$val,", $matches2);
				if (!$key && !array_diff($matches2[1], $cols)) { //! doesn't work with column names containing ",\n
					// first row corresponds to column names - use it for table structure
					$cols = $matches2[1];
					$affected--;
				} else {
					$set = "";
					foreach ($matches2[1] as $i => $col) {
						$set .= ", " . idf_escape($cols[$i]) . " = " . (!strlen($col) && $fields[$cols[$i]]["null"] ? "NULL" : $connection->quote(str_replace('""', '"', preg_replace('~^"|"$~', '', $col))));
					}
					$set = substr($set, 1);
					$result = queries("INSERT INTO " . idf_escape($_GET["select"]) . " SET$set ON DUPLICATE KEY UPDATE$set");
					if (!$result) {
						break;
					}
				}
			}
			queries("COMMIT");
			queries_redirect(remove_from_uri("page"), lang('%d row(s) have been imported.', $affected), $result);
		} else {
			$error = upload_error($file);
		}
	}
}

$table_name = $adminer->tableName($table_status);
page_header(lang('Select') . ": $table_name", $error);

$set = null;
if (isset($rights["insert"])) {
	$set = "";
	foreach ((array) $_GET["where"] as $val) {
		if (count($foreign_keys[$val["col"]]) == 1 && ($val["op"] == "="
			|| (!$val["op"] && !ereg('[_%]', $val["val"])) // LIKE in Editor
		)) {
			$set .= "&set" . urlencode("[" . bracket_escape($val["col"]) . "]") . "=" . urlencode($val["val"]);
		}
	}
}
$adminer->selectLinks($table_status, $set);

if (!$columns) {
	echo "<p class='error'>" . lang('Unable to select the table') . ($fields ? "" : ": " . h($connection->error)) . ".\n";
} else {
	echo "<form action='' id='form'>\n";
	echo "<div style='display: none;'>";
	echo (strlen($_GET["server"]) ? '<input type="hidden" name="server" value="' . h($_GET["server"]) . '">' : "");
	echo (strlen(DB) ? '<input type="hidden" name="db" value="' . h(DB) . '">' : ""); // not used in Editor
	echo '<input type="hidden" name="select" value="' . h($TABLE) . '">';
	echo "</div>\n";
	$adminer->selectColumnsPrint($select, $columns);
	$adminer->selectSearchPrint($where, $columns, $indexes);
	$adminer->selectOrderPrint($order, $columns, $indexes);
	$adminer->selectLimitPrint($limit);
	$adminer->selectLengthPrint($text_length);
	$adminer->selectActionPrint($text_length);
	echo "</form>\n";
	
	$query = "SELECT " . (intval($limit) && $group && count($group) < count($select) ? "SQL_CALC_FOUND_ROWS " : "") . $from . $group_by . (strlen($limit) ? " LIMIT " . intval($limit) . ($_GET["page"] ? " OFFSET " . ($limit * $_GET["page"]) : "") : "");
	echo $adminer->selectQuery($query);
	
	$result = $connection->query($query);
	if (!$result) {
		echo "<p class='error'>" . h($connection->error) . "\n";
	} else {
		$email_fields = array();
		echo "<form action='' method='post' enctype='multipart/form-data'>\n";
		if (!$result->num_rows) {
			echo "<p class='message'>" . lang('No rows.') . "\n";
		} else {
			$rows = array();
			while ($row = $result->fetch_assoc()) {
				$rows[] = $row;
			}
			// use count($rows) without LIMIT, COUNT(*) without grouping, FOUND_ROWS otherwise (slowest)
			$found_rows = (intval($limit) && $group && count($group) < count($select)
				? $connection->result($connection->query(" SELECT FOUND_ROWS()")) // space to allow mysql.trace_mode
				: count($rows)
			);
			
			$backward_keys = $adminer->backwardKeys($TABLE, $table_name);
			
			echo "<table cellspacing='0' class='nowrap' onclick='table_click(event);'>\n";
			echo "<thead><tr><td><input type='checkbox' id='all-page' onclick='form_check(this, /check/);'>";
			$names = array();
			reset($select);
			$order = 1;
			foreach ($rows[0] as $key => $val) {
				$val = $_GET["columns"][key($select)];
				$field = $fields[$select ? $val["col"] : $key];
				$name = ($field ? $adminer->fieldName($field, $order) : "*");
				if (strlen($name)) {
					$order++;
					$names[$key] = $name;
					echo '<th><a href="' . h(remove_from_uri('(order|desc)[^=]*') . '&order%5B0%5D=' . urlencode($key) . ($_GET["order"][0] == $key && !$_GET["desc"][0] ? '&desc%5B0%5D=1' : '')) . '">' . apply_sql_function($val["fun"], $name) . "</a>"; //! columns looking like functions
				}
				next($select);
			}
			echo ($backward_keys ? "<th>" . lang('Relations') : "") . "</thead>\n";
			foreach ($adminer->rowDescriptions($rows, $foreign_keys) as $n => $row) {
				$unique_idf = implode('&', unique_idf($rows[$n], $indexes));
				echo "<tr" . odd() . "><td>" . checkbox("check[]", $unique_idf, in_array($unique_idf, (array) $_POST["check"]), "", "this.form['all'].checked = false; form_uncheck('all-page');") . (count($select) != count($group) || information_schema(DB) ? '' : " <a href='" . h(ME . "edit=" . urlencode($TABLE) . "&$unique_idf") . "'>" . lang('edit') . "</a>");
				foreach ($row as $key => $val) {
					if (isset($names[$key])) {
						$field = $fields[$key];
						if (strlen($val) && (!isset($email_fields[$key]) || strlen($email_fields[$key]))) {
							$email_fields[$key] = (is_email($val) ? $names[$key] : ""); //! filled e-mails may be contained on other pages
						}
						$link = "";
						$val = $adminer->editVal($val, $field);
						if (!isset($val)) {
							$val = "<i>NULL</i>";
						} else {
							if (ereg('blob|binary', $field["type"]) && strlen($val)) {
								$link = h(ME . 'download=' . urlencode($TABLE) . '&field=' . urlencode($key) . "&$unique_idf");
							}
							if (!strlen($val)) {
								$val = "&nbsp;";
							} elseif (strlen($text_length) && ereg('text|blob', $field["type"]) && is_utf8($val)) {
								$val = shorten_utf8($val, max(0, intval($text_length))); // usage of LEFT() would reduce traffic but complicate query
							} else {
								$val = h($val);
							}
							
							if (!$link) { // link related items
								foreach ((array) $foreign_keys[$key] as $foreign_key) {
									if (count($foreign_keys[$key]) == 1 || count($foreign_key["source"]) == 1) {
										foreach ($foreign_key["source"] as $i => $source) {
											$link .= where_link($i, $foreign_key["target"][$i], $rows[$n][$source]);
										}
										$link = h((strlen($foreign_key["db"]) ? preg_replace('~([?&]db=)[^&]+~', '\\1' . urlencode($foreign_key["db"]), ME) : ME) . 'select=' . urlencode($foreign_key["table"]) . $link); // InnoDB supports non-UNIQUE keys
										break;
									}
								}
							}
						}
						if (!$link && is_email($val)) {
							$link = "mailto:$val";
						}
						if (!$link && is_url($val)) {
							$link = "http://www.adminer.org/redirect/?url=" . urlencode($val); // intermediate page to hide Referer
						}
						$val = $adminer->selectVal($val, $link, $field);
						echo "<td>$val";
					}
				}
				$adminer->backwardKeysPrint($backward_keys, $rows[$n]);
				echo "</tr>\n"; // close to allow white-space: pre
			}
			echo "</table>\n";
			
			if (intval($limit) && count($group) >= count($select)) {
				// slow with big tables
				ob_flush();
				flush();
				$found_rows = $connection->result($connection->query("SELECT COUNT(*) FROM " . idf_escape($TABLE) . ($where ? " WHERE " . implode(" AND ", $where) : "")));
			}
			echo "<p class='pages'>";
			if (intval($limit) && $found_rows > $limit) {
				// display first, previous 3, next 3 and last page
				$max_page = floor(($found_rows - 1) / $limit);
				echo lang('Page') . ":" . pagination(0) . ($_GET["page"] > 3 ? " ..." : "");
				for ($i = max(1, $_GET["page"] - 2); $i < min($max_page, $_GET["page"] + 3); $i++) {
					echo pagination($i);
				}
				echo ($_GET["page"] + 3 < $max_page ? " ..." : "") . pagination($max_page);
			}
			echo " (" . lang('%d row(s)', $found_rows) . ") " . checkbox("all", 1, 0, lang('whole result')) . "\n";
			
			echo (information_schema(DB) ? "" : "<fieldset><legend>" . lang('Edit') . "</legend><div><input type='submit' name='edit' value='" . lang('Edit') . "'> <input type='submit' name='clone' value='" . lang('Clone') . "'> <input type='submit' name='delete' value='" . lang('Delete') . "' onclick=\"return confirm('" . lang('Are you sure?') . " (' + (this.form['all'].checked ? $found_rows : form_checked(this, /check/)) + ')');\"></div></fieldset>\n");
			print_fieldset("export", lang('Export'));
			echo $adminer->dumpOutput(1) . " " . $adminer->dumpFormat(1); // 1 - select
			echo " <input type='submit' name='export' value='" . lang('Export') . "'>\n";
			echo "</div></fieldset>\n";
		}
		print_fieldset("import", lang('CSV Import'), !$result->num_rows);
		echo "<input type='hidden' name='token' value='$token'><input type='file' name='csv_file'> <input type='submit' name='import' value='" . lang('Import') . "'>\n";
		echo "</div></fieldset>\n";
		
		$adminer->selectEmailPrint(array_filter($email_fields, 'strlen'), $columns);
		
		echo "</form>\n";
	}
}

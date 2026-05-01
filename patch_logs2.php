<?php
$content = file_get_contents('c:\xampp\htdocs\project_hub\student_dashboard.php');

$startBlock = '<div class="folder-block" style="border-left: 5px solid #8B5CF6; background: var(--input-bg);">';
$endBlock = '</div>
                        <?php endforeach; ?>';

$startPos = strpos($content, $startBlock);
$endPos = strpos($content, $endBlock, $startPos);

if ($startPos !== false && $endPos !== false) {
    $existingHtml = substr($content, $startPos, $endPos - $startPos);
    
    // We compose the new HTML completely manually to avoid any old vs new differences matching
    $newHtml = <<<HTML
<div class="folder-block" style="border-left: 5px solid #8B5CF6; background: var(--input-bg);">
                                <div style="display:flex; justify-content:space-between; align-items:start; margin-bottom:0; cursor:pointer;" onclick="document.getElementById('log_details_<?php echo \$log['id']; ?>').style.display = document.getElementById('log_details_<?php echo \$log['id']; ?>').style.display === 'none' ? 'block' : 'none';">
                                    <div>
                                        <h4 style="font-size:18px; color:var(--text-dark); margin:0;"><?php echo htmlspecialchars(\$log['log_title']); ?></h4>
                                        <p style="font-size:12px; color:var(--text-light); margin:4px 0;">
                                            <i class="fa-solid fa-calendar-day"></i> <?php echo \$log['log_date'] ? date('M d, Y', strtotime(\$log['log_date'])) : 'No date set'; ?> - <?php echo !empty(\$log['log_date_to']) ? date('M d, Y', strtotime(\$log['log_date_to'])) : 'No date set'; ?>
                                            | Created by <?php echo htmlspecialchars(\$log['created_by_name']); ?>
                                        </p>
                                    </div>
                                    <div style="display:flex; gap:10px; align-items:center;">
                                        <button type="button" onclick="event.stopPropagation(); openEditLogModal(<?php echo \$log['id']; ?>)" style="background:none; border:none; color:var(--primary-green); cursor:pointer; font-size:16px;">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>
                                        <form method="POST" onsubmit="return confirm('Delete this entire log entry?');" style="margin:0;" onclick="event.stopPropagation();">
                                            <input type="hidden" name="log_id" value="<?php echo \$log['id']; ?>">
                                            <button type="submit" name="delete_log_student" style="background:none; border:none; color:#EF4444; cursor:pointer; font-size:16px;">
                                                <i class="fa-solid fa-trash-can"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                                <div id="log_details_<?php echo \$log['id']; ?>" style="display:none; margin-top:20px; border-top:1px solid var(--border-color); padding-top:15px;">
                                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom:20px;">
                                        <div style="background:var(--card-bg); padding:15px; border-radius:12px; border:1px solid var(--border-color);">
                                            <label style="font-size:11px; text-transform:uppercase; font-weight:700; color:#8B5CF6; display:block; margin-bottom:8px;">Summary of Planned Tasks</label>
                                            <div style="font-size:13px; color:var(--text-dark); line-height:1.5;">
                                                <?php 
                                                try {
                                                    \$tasks = json_decode(\$log['progress_planned'], true);
                                                    if (is_array(\$tasks)) {
                                                        foreach(\$tasks as \$t) {
                                                            if(!empty(\$t['planned'])) echo "• " . htmlspecialchars(\$t['planned']) . "<br>";
                                                        }
                                                    } else {
                                                        echo nl2br(htmlspecialchars(\$log['progress_planned']));
                                                    }
                                                } catch(Exception \$e) {
                                                    echo nl2br(htmlspecialchars(\$log['progress_planned']));
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        <div style="background:var(--card-bg); padding:15px; border-radius:12px; border:1px solid var(--border-color);">
                                            <label style="font-size:11px; text-transform:uppercase; font-weight:700; color:#10B981; display:block; margin-bottom:8px;">Work Status: <?php echo htmlspecialchars(\$log['progress_achieved'] ?: 'Working'); ?></label>
                                            <div style="font-size:13px; color:var(--text-dark); line-height:1.5;">
                                                <?php 
                                                try {
                                                    if (is_array(\$tasks)) {
                                                        foreach(\$tasks as \$t) {
                                                            if(!empty(\$t['achieved'])) echo "✓ " . htmlspecialchars(\$t['achieved']) . "<br>";
                                                        }
                                                    } else {
                                                        echo "Current Status: " . htmlspecialchars(\$log['progress_achieved']);
                                                    }
                                                } catch(Exception \$e) {}
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div style="margin-top:10px;">
                                        <label style="font-size:11px; text-transform:uppercase; font-weight:700; color:#3B82F6; display:block; margin-bottom:8px;">Guide Review</label>
                                        <div style="background:var(--card-bg); padding:15px; border-radius:12px; border:1px solid var(--border-color); font-size:13px; color:var(--text-dark); line-height:1.5;">
                                            <?php echo !empty(\$log['guide_review']) ? nl2br(htmlspecialchars(\$log['guide_review'])) : '<span style="color:var(--text-light); font-style:italic;">No review provided yet.</span>'; ?>
                                        </div>
                                    </div>
                                
HTML;
    $content = substr_replace($content, $newHtml, $startPos, $endPos - $startPos);
    file_put_contents('c:\xampp\htdocs\project_hub\student_dashboard.php', $content);
    echo "Patched successfully!\n";
} else {
    echo "Fail to locate block\n";
}

<?php
$content = file_get_contents('c:\xampp\htdocs\project_hub\student_dashboard.php');

$oldBody = <<<HTML
                                <div style="display:flex; justify-content:space-between; align-items:start; margin-bottom:15px;">
                                    <div>
                                        <h4 style="font-size:18px; color:var(--text-dark); margin:0;"><?php echo htmlspecialchars(\$log['log_title']); ?></h4>
                                        <p style="font-size:12px; color:var(--text-light); margin:4px 0;">
                                            <i class="fa-solid fa-calendar-day"></i> <?php echo \$log['log_date'] ? date('M d, Y', strtotime(\$log['log_date'])) : 'No date set'; ?> 
                                            | Created by <?php echo htmlspecialchars(\$log['created_by_name']); ?>
                                        </p>
                                    </div>
                                    <div style="display:flex; gap:10px; align-items:center;">
                                        <button onclick="openEditLogModal(<?php echo \$log['id']; ?>)" style="background:none; border:none; color:var(--primary-green); cursor:pointer; font-size:16px;">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>
                                        <form method="POST" onsubmit="return confirm('Delete this entire log entry?');" style="margin:0;">
                                            <input type="hidden" name="log_id" value="<?php echo \$log['id']; ?>">
                                            <button type="submit" name="delete_log_student" style="background:none; border:none; color:#EF4444; cursor:pointer; font-size:16px;">
                                                <i class="fa-solid fa-trash-can"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>

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

                                <?php if (!empty(\$log['log_entries'])): ?>
                                    <div style="margin-top:10px;">
                                        <label style="font-size:11px; text-transform:uppercase; font-weight:700; color:var(--text-light); display:block; margin-bottom:10px;">Updates / Details</label>
                                        <?php foreach(\$log['log_entries'] as \$entry): ?>
                                            <div style="background: var(--card-bg); padding: 10px 15px; border-radius: 8px; margin-bottom: 8px; border-left: 3px solid var(--primary-green); display:flex; justify-content:space-between; align-items:center;">
                                                <div style="font-size: 13px; color: var(--text-dark);"><?php echo htmlspecialchars(\$entry['description']); ?></div>
                                                <div style="font-size: 11px; color: var(--text-light);"><?php echo date('M j, H:i', strtotime(\$entry['date'])); ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <div style="margin-top:15px; padding-top:15px; border-top:1px solid var(--border-color);">
                                    <form method="POST" style="display:flex; gap:10px;">
                                        <input type="hidden" name="log_id" value="<?php echo \$log['id']; ?>">
                                        <input type="text" name="description" placeholder="Add a detail or quick update..." class="g-input" style="flex:1; font-size:13px;" required>
                                        <button type="submit" name="add_log_entry_student" class="btn-edit-form" style="background:var(--primary-green);">
                                            <i class="fa-solid fa-plus"></i> Add
                                        </button>
                                    </form>
                                </div>
HTML;

$newBody = <<<HTML
                                <div style="display:flex; justify-content:space-between; align-items:start; margin-bottom:0; cursor:pointer;" onclick="document.getElementById('log_details_<?php echo \$log['id']; ?>').style.display = document.getElementById('log_details_<?php echo \$log['id']; ?>').style.display === 'none' ? 'block' : 'none';">
                                    <div>
                                        <h4 style="font-size:18px; color:var(--text-dark); margin:0;"><?php echo htmlspecialchars(\$log['log_title']); ?></h4>
                                        <p style="font-size:12px; color:var(--text-light); margin:4px 0;">
                                            <i class="fa-solid fa-calendar-day"></i> <?php echo \$log['log_date'] ? date('M d, Y', strtotime(\$log['log_date'])) : 'N/A'; ?> - <?php echo \$log['log_date_to'] ? date('M d, Y', strtotime(\$log['log_date_to'])) : 'N/A'; ?> 
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
                                </div>
HTML;

if (strpos($content, trim(explode("\n", $oldBody)[0])) !== false) {
    $content = str_replace($oldBody, $newBody, $content);
    file_put_contents('c:\xampp\htdocs\project_hub\student_dashboard.php', $content);
    echo "Replaced successfully!\n";
} else {
    echo "Old string not found.\n";
}
?>
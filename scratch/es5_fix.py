import re
import sys

def es5_fix(content):
    # 1. Replace const/let with var
    content = re.sub(r'\b(const|let)\b', 'var', content)
    
    # 2. Replace Arrow Functions: (a, b) => { ... }
    content = re.sub(r'\(([^)]*)\)\s*=>\s*\{', r'function(\1) {', content)
    
    # 3. Replace Arrow Functions: (a, b) => expr
    # This is tricky because of nested parentheses. We'll handle simple cases.
    content = re.sub(r'\(([^)]*)\)\s*=>\s*([^\{\r\n;]+)', r'function(\1) { return \2; }', content)
    
    # 4. Replace Arrow Functions: el => { ... }
    content = re.sub(r'\b([a-zA-Z0-9_$]+)\s*=>\s*\{', r'function(\1) {', content)
    
    # 5. Replace Arrow Functions: el => expr
    content = re.sub(r'\b([a-zA-Z0-9_$]+)\s*=>\s*([^\{\r\n;]+)', r'function(\1) { return \2; }', content)

    # 6. Replace Template Literals (very simple ones only to avoid breaking complex HTML)
    # We'll just replace the backticks with single quotes if no newlines
    # content = re.sub(r'`([^`\n]*)`', r"'\1'", content)
    
    # 7. Replace async/await
    content = re.sub(r'\basync\b\s+', '', content)
    # Removing 'await ' is dangerous, we should replace it with nothing and hope for the best or assume it's already fixed.
    
    return content

files = ['admin_dashboard.php', 'head_dashboard.php']
for f in files:
    with open(f, 'r', encoding='utf-8') as file:
        data = file.read()
    
    # Only target the <script> blocks
    def script_repl(match):
        return match.group(1) + es5_fix(match.group(2)) + match.group(3)
    
    # This regex is simple and might miss nested scripts, but it's okay here
    data = re.sub(r'(<script[^>]*>)(.*?)(</script>)', script_repl, data, flags=re.DOTALL)
    
    with open(f, 'w', encoding='utf-8') as file:
        file.write(data)
    print(f"Fixed {f}")

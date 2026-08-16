<script>
// Tab switching functionality
function showTab(tabName) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
    });
    
    // Remove active class from all tab buttons
    document.querySelectorAll('.tab-button').forEach(button => {
        button.classList.remove('active');
    });
    
    // Show selected tab content
    document.getElementById(`${tabName}-tab`).classList.add('active');
    
    // Add active class to clicked button
    event.target.closest('.tab-button').classList.add('active');
}

// Copy XML functionality
function copyXML() {
    const xmlContent = document.getElementById('xml-content').textContent;
    const button = document.querySelector('.copy-button');
    
    navigator.clipboard.writeText(xmlContent).then(() => {
        const originalText = button.innerHTML;
        button.innerHTML = '<i class="fa fa-check"></i> تم النسخ';
        button.classList.add('copied');
        
        setTimeout(() => {
            button.innerHTML = originalText;
            button.classList.remove('copied');
        }, 2000);
    }).catch(err => {
        console.error('Failed to copy: ', err);
        alert('فشل في نسخ النص');
    });
}

// Download XML functionality
function downloadXML() {
    const xmlContent = document.getElementById('xml-content').textContent;
    const blob = new Blob([xmlContent], { type: 'application/xml' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `courses_${new Date().getTime()}.xml`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

// Refresh data functionality
function refreshData() {
    const button = event.target.closest('.btn-modern');
    const originalText = button.innerHTML;
    
    button.innerHTML = '<i class="fa fa-spin fa-spinner"></i> جاري التحديث...';
    button.disabled = true;
    
    // Reload the page after a short delay
    setTimeout(() => {
        window.location.reload();
    }, 1000);
}

// Syntax highlighting for XML
document.addEventListener('DOMContentLoaded', function() {
    const xmlContent = document.getElementById('xml-content');
    let content = xmlContent.textContent;
    
    // Basic XML syntax highlighting
    content = content
        .replace(/(&lt;\/?)([^&\s]+)([^&]*?)(&gt;)/g, '<span class="xml-tag">$1$2</span><span class="xml-attribute">$3</span><span class="xml-tag">$4</span>')
        .replace(/(\w+)=(&quot;[^&]*?&quot;)/g, '<span class="xml-attribute">$1</span>=<span class="xml-value">$2</span>');
    
    xmlContent.innerHTML = content;
});
</script>

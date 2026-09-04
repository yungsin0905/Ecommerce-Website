<?php include "sidebar.php" ?>
<?php include "aheader.php" ?>

<style>
:root {
    --sidebar-width: 200px;
    --collapsed-sidebar-width: 75px;
    --header-height: 45px;
}
    
/* Sidebar, Header, Main content changed when sidebar is collapsed. */
.main {
    margin-left: var(--sidebar-width);
    padding: 20px;
    margin-top: var(--header-height);
    transition: all 0.3s ease;
}

.global-layout.collapsed .main { 
    margin-left: var(--collapsed-sidebar-width); 
}

.global-layout.collapsed .sidebar-cont { 
    width: var(--collapsed-sidebar-width); 
    transition: width 0.2s ease;
}

.global-layout.collapsed .sidebar-logo {
    height: 30px;
}

.global-layout.collapsed .brand-text {
    opacity: 0;
}

.global-layout.collapsed .sidebar-menu a i{
    font-size: 20px;
}

.global-layout.collapsed .sidebar-menu .sidebar-text {
    display: none;
}

.global-layout.collapsed .sub-sidebar-menu {
    padding-left: 0px;
}

.global-layout.collapsed .header-cont { 
    width: calc(100% - var(--collapsed-sidebar-width)); 
    left: var(--collapsed-sidebar-width); 
    transition: all 0.3s ease;
}

.global-layout.collapsed .icon-expand {
    display: block;
}

.global-layout.collapsed .icon-collapse {
    display: none;
}
</style>

<script>
document.addEventListener("DOMContentLoaded", function () {

    const sidebar = document.querySelector('.global-layout');
    const sidebarCtrlBtn = document.querySelector('.sidebar-ctrl-btn');

    if (!sidebar || !sidebarCtrlBtn) return;

    function updateTitle() {
        const isCollapsed = sidebar.classList.contains('collapsed');
        sidebarCtrlBtn.title = isCollapsed ? 'Expand Sidebar' : 'Collapse Sidebar';
    }

    sidebarCtrlBtn.addEventListener("click", function () {

    sidebar.classList.toggle('collapsed');

    updateTitle();

    // wait sidebar animation finish
    setTimeout(() => {

        // resize all Chart.js charts
        if (window.Chart) {
            Object.values(Chart.instances).forEach(chart => {
                chart.resize();
            });
        }

        // resize FullCalendar
        if (window.calendar) {
            calendar.updateSize();
        }

        // trigger general resize
        window.dispatchEvent(new Event('resize'));

    }, 300);

});

    updateTitle();
});
</script>


Ext.namespace('ui','ui.component','ui.component._BugsGrid');

//------------------------------------------------------------------------------
// BugsGrid internals

// Store : All open bugs for documentation
ui.component._BugsGrid.store = new Ext.data.Store({
    proxy : new Ext.data.HttpProxy({
        url : './php/controller.php'
    }),
    baseParams : { task : 'getOpenBugs' },
    reader : new Ext.data.JsonReader(
        {
            root          : 'Items',
            totalProperty : 'nbItems',
            id            : 'id'
        }, Ext.data.Record.create([
            {
                name    : 'id',
                mapping : 'id'
            }, {
                name    : 'title',
                mapping : 'title'
            }, {
                name    : 'link',
                mapping : 'link'
            }, {
                name    : 'description',
                mapping : 'description'
            }
        ])
    )
});

// BugsGrid columns definition
ui.component._BugsGrid.columns = [{
    id        : 'GridBugTitle',
    header    : "Title",
    sortable  : true,
    dataIndex : 'title'
}];

ui.component._BugsGrid.view = new Ext.grid.GridView({
    forceFit      : true,
    emptyText     : _('No open Bugs'),
    enableRowBody : true,
    getRowClass   : function(record, rowIndex, p, store)
    {
        p.body = '<p class="bug-desc">' + record.data.description + '</p>';
        return 'x-grid3-row-expanded';
    }
});

//------------------------------------------------------------------------------
// BugsGrid
ui.component.BugsGrid = Ext.extend(Ext.grid.GridPanel,
{
    iconCls          : 'iconBugs',
    loadMask         : true,
    stripeRows       : true,
    autoHeight       : true,
    width            : 800,
    autoExpandColumn : 'GridBugTitle',
    store            : ui.component._BugsGrid.store,
    columns          : ui.component._BugsGrid.columns,
    view             : ui.component._BugsGrid.view,
    sm               : new Ext.grid.RowSelectionModel({ singleSelect: true }),

    listeners : {
        render : function(grid)
        {
            grid.store.load.defer(20, grid.store);
        },
        rowcontextmenu : function(grid, rowIndex, e)
        {
            grid.getSelectionModel().selectRow(rowIndex);

            new Ext.menu.Menu({
                id    : 'submenu',
                items : [{
                    text    : '<b>'+_('Open in a new Tab')+'</b>',
                    iconCls : 'openInTab',
                    handler : function()
                    {
                        var BugsId    = grid.store.getAt(rowIndex).data.id,
                            BugsUrl   = grid.store.getAt(rowIndex).data.link,
                            BugsTitle = grid.store.getAt(rowIndex).data.title;

                        phpDoc.NewTabBugs(BugsId, BugsUrl, BugsTitle);
                    }
                }, '-', {
                    text    : _('Refresh this grid'),
                    iconCls : 'refresh',
                    handler : function()
                    {
                        grid.store.reload();
                    }
                }]
            }).showAt(e.getXY());
        },
        rowdblclick : function(grid, rowIndex, e)
        {
            var BugsId    = grid.store.getAt(rowIndex).data.id,
                BugsUrl   = grid.store.getAt(rowIndex).data.link,
                BugsTitle = grid.store.getAt(rowIndex).data.title;

            phpDoc.NewTabBugs(BugsId, BugsUrl, BugsTitle);
        }
    },

    initComponent : function()
    {
        Ext.apply(this,
        {
            title : String.format(_('Open bugs for {0}'), 'doc-' + phpDoc.userLang),
            tbar  : [{
                tooltip : _('Refresh this grid'),
                iconCls : 'refresh',
                handler : function()
                {
                    ui.component.BugsGrid.reload();
                }
            }]
        });
        ui.component.BugsGrid.superclass.initComponent.call(this);
    }
});

ui.component.BugsGrid.reload = function()
{
    ui.component._BugsGrid.store.reload();
}

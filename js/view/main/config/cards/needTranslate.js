Ext.define('phpdoe.view.main.config.cards.needTranslate', {
    extend  : 'Ext.tab.Panel',
    id         : 'conf-card-need-translate',
    activeTab: 0,      // First tab active by default,
    defaults   : {
        bodyStyle: 'padding: 5px;',
        bodyStyle: 'padding: 5px;',
        autoHeight : true,
        overflowY: 'scroll'
    },

    initComponent : function () {

        this.items = [
            {
                title   : 'Menu',
                iconCls : 'iconMenu',
                labelAlign: 'top',
                layout:'form',
                items   : [
                    {
                        xtype   : 'fieldset',
                        title   : 'Nb files to display',
                        iconCls : 'iconFilesToDisplay',
                        items   : [
                            {
                                xtype      : 'numberfield',
                                id          : 'config-newFile-nbDisplay',
                                hideLabel : true,
                                size      : 6,
                                name       : 'newFile.nbDisplay',
                                value      : config.user.conf.newFile.nbDisplay || 300,
                                minValue   : 0,
                                maxValue   : 10000,
                                enableKeyEvents : true
                            },
                            {
                                xtype : 'displayfield',
                                value : 'files to display (<span style="font-style:italic">0 means no limit</span>)'
                            }
                        ]
                    }
                ]
            },
            {
                title   : 'User Interface',
                iconCls : 'iconUI',
                labelAlign: 'top',
                layout:'form',
                items   : [
                    {
                        xtype   : 'fieldset',
                        title   : 'ScrollBars',
                        iconCls : 'iconScrollBar',
                        items   : [
                            {
                                xtype       : 'checkbox',
                                id          : 'config-newFile-syncScrollbars',
                                hideLabel : true,
                                name : 'newFile.syncScrollbars',
                                checked : config.user.conf.newFile.syncScrollbars,
                                boxLabel : 'Synchronize scroll bars'
                            }
                        ]
                    },
                    {
                        xtype   : 'fieldset',
                        title   : 'Tools',
                        iconCls : 'iconConf',
                        items   : [
                            {
                                xtype   : 'fieldset',
                                title   : 'Start with the panel open',
                                id: 'config-newFile-toolsPanelDisplay',
                                checkboxToggle: true,
                                checkboxName: 'newFile.toolsPanelDisplay',
                                collapsed : !config.user.conf.newFile.toolsPanelDisplay,
                                items   : [
                                    {
                                        xtype      : 'numberfield',
                                        id          : 'config-newFile-toolsPanelWidth',
                                        hideLabel : true,
                                        size      : 6,
                                        name       : 'newFile.toolsPanelWidth',
                                        value      : config.user.conf.newFile.toolsPanelWidth || 375,
                                        minValue   : 0,
                                        maxValue   : 10000,
                                        enableKeyEvents : true
                                    }
                                ]
                            }
                        ]
                    },
                    {
                        xtype   : 'fieldset',
                        title   : 'Right panel',
                        iconCls : 'iconUI',
                        items   : [
                            {
                                xtype: 'radiogroup',
                                hideLabel: true,
                                name: 'newFile.secondPanel',
                                defaults   : { name: 'newFile.secondPanel' },
                                columns: 1,
                                id      : 'config-newFile-secondPanel',
                                items: [
                                    {
                                        boxLabel: 'Display the original file',
                                        inputValue: 'originalFile',
                                        checked:
                                            config.user.conf.newFile.secondPanel == 'originalFile'
                                    },
                                    {
                                        boxLabel: 'Do not display a right panel',
                                        inputValue: 'none',
                                        checked: !Ext.isDefined(config.user.conf.newFile.secondPanel) || config.user.conf.newFile.secondPanel == 'none'
                                    }
                                ]
                            }
                        ]
                    }
                ]
            }
        ];

        this.callParent();
    }
});
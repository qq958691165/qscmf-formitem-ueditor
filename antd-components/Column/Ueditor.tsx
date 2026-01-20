import {ColumnProps} from "@quansitech/antd-admin/dist/components/Column/types";
import React, {Component} from "react";
import {createScript} from "@quansitech/antd-admin/dist/lib/helpers";
import {Spin} from "antd";
import {ModalContext, ModalContextProps} from "@quansitech/antd-admin/dist/components/ModalContext";
import {debounce, throttle, uniqueId} from "es-toolkit/compat";

declare global {
    interface Window {
        UE: any,
        UE_LOADING_PROMISE: Promise<any>,
    }
}

function findParentElement(element: HTMLElement, selector: string) {
    // 检查当前元素是否匹配选择器
    if (element.matches(selector)) {
        return element;
    }

    // 获取父元素
    const parent = element.parentElement;

    // 如果没有父元素，返回null
    if (!parent) {
        return null;
    }

    // 递归调用函数
    return findParentElement(parent, selector);
}

export default class Ueditor extends Component<ColumnProps & {
    fieldProps: {
        ueditorPath: string,

        extraScripts?: string[],
    }
}, any> {
    modalContext = {} as ModalContextProps

    editor: any = null
    containerRef: HTMLElement | null = null
    contentChangeHandler: (() => void) | null = null
    state = {
        loading: true,
        containerId: uniqueId('ueditor_'),
        width: '',
    }
    err: string = ''

    componentDidMount() {
        this.setState({
            width: this.containerRef?.offsetWidth ? `${this.containerRef?.offsetWidth}px` : '100%'
        })

        if (!window.UE && !window.UE_LOADING_PROMISE) {
            window.UE_LOADING_PROMISE =
                createScript(this.props.fieldProps.configJsPath || this.props.fieldProps.ueditorPath + '/ueditor.config.js')
                    .then(() => {
                        return createScript(this.props.fieldProps.ueditorPath + '/ueditor.all.js?v=260120')
                    })
                    .then(() => {
                        return createScript(this.props.fieldProps.ueditorPath + '/lang/zh-cn/zh-cn.js')
                    })
        }

        this.props.fieldProps.withValidator((v: any)=>{
            if (this.err){
                throw new Error(this.err)
            }
            return true
        })

        const extraPromise = () => {
            // 加加额外脚本
            const promises = this.props.fieldProps.extraScripts?.map((s: string) => {
                if (document.querySelector(`script[src="${s}"]`)) {
                    return () => Promise.resolve()
                }

                return () => createScript(s)
            }) || []

            async function seqExec(promises: (() => Promise<any>)[]) {
                if (promises.length === 0) return;

                await promises[0]();
                await seqExec(promises.slice(1));
            }

            return seqExec(promises)
        }

        window.UE_LOADING_PROMISE.then(async () => {
            await extraPromise()
            let that = this
            window.UE.plugins['forceCatchRemoteImg'] = function () {
                if (this.options.forcecatchremote) {
                    // @ts-ignore
                    this.addListener("afterpaste", function (t: any, a: any) {
                        const load_src = that.props.fieldProps.ueditorPath + '/img/load.gif';
                        const domUtils = window.UE.dom.domUtils;

                        const parser = new DOMParser();
                        const pasteDom = parser.parseFromString(a.html, "text/html");

                        // @ts-ignore
                        const allImgs = domUtils.getElementsByTagName(this.document, "img");
                        const imgs = domUtils.getElementsByTagName(pasteDom, "img");

                        let notCatch = (src: string) => {
                            if (src.indexOf(location.host) !== -1) {
                                return true;
                            }
                            if (/(^\.)|(^\/)/.test(src)) {
                                return true;
                            }
                            return src.indexOf('img_catch_success') !== -1;

                        }

                        let catchGo = false;
                        for (let i = 0; i < imgs.length; i++) {
                            if (notCatch(imgs[i].src)) {
                                continue
                            }
                            for (let l = 0; l < allImgs.length; l++) {
                                if (allImgs[l].src == imgs[i].src) {
                                    catchGo = true;
                                    domUtils.setAttributes(allImgs[l], {
                                        "src": load_src,
                                        "_src": allImgs[l].src
                                    });
                                }
                            }
                        }

                        if (catchGo) {
                            //     $('.submit').trigger('startHandlePostData', '正在抓取图片');
                            that.setState({loading: true})
                            that.err = '抓取图片中';
                            that.props.fieldProps.onChange(that.editor?.getContent().trim() + ' ')
                        }
                    });

                    this.addListener("catchremotesuccess", function () {
                        that.err = '';
                        that.props.fieldProps.onChange(that.editor?.getContent().trim())
                        that.setState({loading: false})
                        //     $('.submit').trigger('endHandlePostData');
                    });
                }

            }

            // plugins/insert_richtext.js
            /**
             * 抓取微信富文本
             */
            window.UE.plugin.register('insert_richtext', function () {
                // @ts-ignore
                const me = this;

                function filter(div: HTMLElement) {
                    const domUtils = window.UE.dom.domUtils;
                    const browser = window.UE.browser;
                    const utils = window.UE.utils;

                    let html;
                    let ci;
                    if (div.firstChild) {
                        //去掉cut中添加的边界值
                        const nodes = domUtils.getElementsByTagName(div, 'span');
                        for (let i = 0, ni; ni = nodes[i++];) {
                            if (ni.id == '_baidu_cut_start' || ni.id == '_baidu_cut_end') {
                                domUtils.remove(ni);
                            }
                        }

                        if (browser.webkit) {

                            let brs = div.querySelectorAll('div br');
                            for (let i = 0, bi; bi = brs[i++];) {
                                const pN = bi.parentNode as HTMLElement;
                                if (pN) {
                                    if (pN.tagName == 'DIV' && pN.childNodes.length == 1) {
                                        pN.innerHTML = '<p><br/></p>';
                                        domUtils.remove(pN);
                                    }
                                }
                            }
                            const divs = div.querySelectorAll('#baidu_pastebin');
                            for (let i = 0, di; di = divs[i++];) {
                                const tmpP = me.document.createElement('p');
                                di.parentNode?.insertBefore(tmpP, di);
                                while (di.firstChild) {
                                    tmpP.appendChild(di.firstChild);
                                }
                                domUtils.remove(di);
                            }

                            const metas = div.querySelectorAll('meta');
                            for (let i = 0, ci; ci = metas[i++];) {
                                domUtils.remove(ci);
                            }

                            brs = div.querySelectorAll('br');
                            for (let i = 0; ci = brs[i++];) {
                                if (/^apple-/i.test(ci.className)) {
                                    domUtils.remove(ci);
                                }
                            }
                        }
                        if (browser.gecko) {
                            const dirtyNodes = div.querySelectorAll('[_moz_dirty]');
                            for (let i = 0; ci = dirtyNodes[i++];) {
                                ci.removeAttribute('_moz_dirty');
                            }
                        }
                        if (!browser.ie) {
                            const spans = div.querySelectorAll('span.Apple-style-span');
                            for (let i = 0, ci; ci = spans[i++];) {
                                domUtils.remove(ci, true);
                            }
                        }

                        //ie下使用innerHTML会产生多余的\r\n字符，也会产生&nbsp;这里过滤掉
                        html = div.innerHTML;//.replace(/>(?:(\s|&nbsp;)*?)</g,'><');

                        //过滤word粘贴过来的冗余属性
                        html = window.UE.filterWord(html);
                        //取消了忽略空白的第二个参数，粘贴过来的有些是有空白的，会被套上相关的标签
                        var root = window.UE.htmlparser(html);

                        //如果给了过滤规则就先进行过滤
                        if (me.options.filterRules) {
                            window.UE.filterNode(root, me.options.filterRules);
                        }
                        //执行默认的处理
                        me.filterInputRule(root);
                        //针对chrome的处理
                        if (browser.webkit) {
                            var br = root.lastChild();
                            if (br && br.type == 'element' && br.tagName == 'br') {
                                root.removeChild(br)
                            }
                            utils.each(me.body.querySelectorAll('div'), function (node: HTMLElement) {
                                if (domUtils.isEmptyBlock(node)) {
                                    domUtils.remove(node, true)
                                }
                            })
                        }
                        html = {'html': root.toHtml()};
                        me.fireEvent('beforepaste', html, root);
                        //抢了默认的粘贴，那后边的内容就不执行了，比如表格粘贴
                        if (!html.html) {
                            return;
                        }
                        root = window.UE.htmlparser(html.html, true);
                        //如果开启了纯文本模式
                        if (me.queryCommandState('pasteplain') === 1) {
                            me.execCommand('insertHtml', window.UE.filterNode(root, me.options.filterTxtRules).toHtml(), true);
                        } else {
                            //文本模式
                            window.UE.filterNode(root, me.options.filterTxtRules);
                            const txtContent = root.toHtml();
                            //完全模式
                            const htmlContent = html.html;

                            const address = me.selection.getRange().createAddress(true);
                            // @ts-ignore
                            me.execCommand('insertHtml', me.getOpt('retainOnlyLabelPasted') === true ? getPureHtml(htmlContent) : htmlContent, true);
                        }

                        me.fireEvent("afterpaste", html);
                    }
                }


                return {
                    bindEvents: {
                        'afterInsertRichText': function (e: Event, html: HTMLElement) {
                            me.execCommand('cleardoc');
                            filter.call(me, html);
                            me.document.body.innerHTML = `<section>${html}</section>`;

                            that.setState({loading: true})
                            that.err = '抓取图片中'
                            that.props.fieldProps.onChange(that.editor?.getContent().trim() + ' ')
                            me.fireEvent('catchremoteimage');
                        },
                    },
                    commands: {}
                }
            });


            const config = {
                forcecatchremote: true,
                ...this.props.fieldProps?.config,
            }
            if (this.modalContext?.inModal) {
                config.zIndex = 2000
                config.topOffset = 0
            }

            this.editor = window.UE.getEditor(this.state.containerId, config)
            if (this.modalContext?.inModal) {
                this.editor.scrollContainer = findParentElement(this.containerRef as HTMLElement, '.ant-modal-wrap')
            }

            this.editor?.ready(() => {
                const value = this.props.fieldProps.value
                if (value) {
                    this.editor?.setContent(value || '')
                    this.props.fieldProps.onChange(this.editor?.getContent())
                }

                // 监听内容变化
                this.contentChangeHandler = debounce(() => {
                    this.props.fieldProps.onChange(this.editor?.getContent().trim())
                }, 300);
                this.editor?.addListener('contentChange selectionchange keyup afterpaste', this.contentChangeHandler)
                this.editor?.addListener('fullscreenchanged', () => {
                    this.contentChangeHandler && this.contentChangeHandler()
                })

                this.setState({loading: false})
            })
        })

    }

    componentWillUnmount() {
        this.editor?.destroy()
    }

    render() {
        return <ModalContext.Consumer>
            {
                (modalContext: ModalContextProps) => {
                    this.modalContext = modalContext
                    return <div ref={el => this.containerRef = el}>
                        <Spin spinning={this.state.loading}>
                            <textarea id={this.state.containerId} style={{width: this.state.width}}/>
                        </Spin>
                    </div>
                }
            }
        </ModalContext.Consumer>
    }
}
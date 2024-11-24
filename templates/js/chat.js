
    const
        socket_url = 'ws://localhost:2000/chat',
        main_container = document.getElementById('main-container');
        chat_form = document.getElementById('chat_form'),
        message_input = document.getElementById('message_input'),
        chat_messages_container = document.getElementById('chat_messages'),
        chat_window = document.getElementById('chat_window'),
        send_message = document.getElementById('send_message'),
        search_users_popover = document.getElementById('new-chat-popover'),
        no_chat_selected = document.getElementById('no-chat-selected'),
        user_list = document.getElementById('user_list'),
        search_users_input = search_users_popover.querySelector('[search_input]'),
        search_users_loader = search_users_popover.querySelector('[search_loader]'),
        search_users_result = search_users_popover.querySelector('[search_results]'),
        selected_user_heading = document.getElementById('chat_selected_user');

    let user_list_container = document.getElementById('user_list_container');

    const intersection_observer_options = {
        root: chat_messages_container,
        rootMargin: "0px",
        threshold: 0.1  ,
    };

    const message_seen_observer = new IntersectionObserver((entries, observer) => {

        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.mark_as_read();
                observer.unobserve(entry.target); // Stop observing once seen
            }
        });

    }, intersection_observer_options);


    let socket = null;
    let active_room_id = null;

    let all_chats_list = new Map;
    let username_to_room_id_cache = new Map;
    let temp_uuid_to_message = new Map;
    let id_to_message_cache = new Map;

    let chat_list_loaded = false;

    class ChatStatics {
        static MSG_TEXT = 'text';
        static MSG_FILE = 'file';
        static EVENT_MESSAGE = 'msg';
        static EVENT_ACTION = 'action';
        static EVENT_READ = 'rd';
        static STATUS_DELIVERED = 'dlvrd';
        static STATUS_READ = 'rd';
    }

    class MessageItem {

        static LHS = 0;
        static RHS = 1;
        constructor(assign) {
            this.side = MessageItem.LHS;
            this.is_sent = false;
            this.is_delivered = false;
            this.is_seen = false;
            Object.assign(this, assign);
            if (!this.sent_at) {
                this.sent_at = Date.now();
            }
        }
        read() {

            socket.send(ChatStatics.EVENT_READ, { type: ChatStatics.STATUS_READ, room_id: this.parent_chat.room_id, m_id: this.id });
            this.mark_as_read();
            this.parent_chat.refreshUnseenCounter();

        }
        mark_as_read() {

            this.is_seen = true;
            this.setStatus();

        }
        delivered(data) {
            this.is_delivered = true;
            Object.assign(this, data)
            if (this.id) {

                id_to_message_cache.set(this.id, this);

            }
            this.setStatus();
        }
        send() {
            temp_uuid_to_message.set(this.temp_uuid, this);
            socket.send(ChatStatics.EVENT_MESSAGE, { room_id: this.room_id, m: this.message, temp_uuid: this.temp_uuid });
            this.is_sent = true;
            this.parent_chat.pushMessage(this);
        }
        setStatus() {

            var status = this.element.querySelector('[data-status]');
            if( !status ) {
                return;
            }

            status.textContent = '';
            if (this.is_seen) {
                status.textContent = 'seen';
            } else if ( this.is_delivered ) {
                status.textContent = 'delivered';
            } else if ( this.is_sent ) {
                status.textContent = 'sent';
            }

        }

    }

    class ChatItem {

        constructor(assign) {
            this.chat = [];
            this.chat_loaded = false;
            this.room_id = null;
            this.active = false;
            Object.assign(this, assign);
            if (assign.room_id) {
                username_to_room_id_cache.set(this.username, assign.room_id);
            }
            this.unseen_messages = 0;
        }

        refreshUnseenCounter() {

            this.unseen_messages = 0;

            this.chat.forEach(m => {

                if (MessageItem.LHS === m.side && !m.is_seen) {
                    this.unseen_messages++;
                }

            });

            var count_element = this.element.querySelector('[data-unseen-count]');

            if (this.unseen_messages) {
                count_element.classList.remove('hidden');
                count_element.textContent = this.unseen_messages < 10 ? this.unseen_messages : '9+';
            } else {
                count_element.classList.add('hidden');
            }

        }

        loadChat() {

            return new Promise((resolve, reject) => {

                if (this.chat_loaded) {

                    resolve(this.chat);

                } else {


                    ajax_request(`${AJAX_ENDPOINT}/chat/messages?room_id=${this.room_id ? this.room_id : ''}&username=${this.username}&offset=${this.chat.length}`, {
                        headers: {
                            'AuthToken': auth_token
                        }
                    }, response => {

                        if (!response.success) {
                            reject(response.message);
                            return;
                        }

                        if (response.data?.hasOwnProperty('room_id')) {
                            this.room_id = response.data.room_id;
                            username_to_room_id_cache.set(this.username, this.room_id);
                        }

                        if (Array.isArray(response.data.messages)) {
                            response.data.messages.forEach(message => {

                                var m = new MessageItem({ ...message, side: message.sender == current_user.id ? MessageItem.RHS : MessageItem.LHS });
                                this.pushMessage(m);

                            });
                            resolve(true);
                            this.chat_loaded = true;
                            this.refreshUnseenCounter();
                        } else {
                            reject("Invalid data");
                        }

                    }, e => {

                        reject(e);

                    })

                }

            })

        }

        closeChat() {
            this.active = false;
            this.element.classList.remove('bg-custom-secondary');
        }

        renderChat() {

            this.active = true;
            this.element.classList.add('bg-custom-secondary');

            chat_messages_container.textContent = '';
            for (let i = 0; i < 10; i++) {
                chat_messages_container.append(create_chat_message_skeleton(i % 2));
            }

            selected_user_heading.textContent = this.username;

            chat_messages_container.textContent = '';

            setTimeout(() => {

                var unseen_spotted = false;
                this.chat.forEach(c => {

                    var element = append_message_element(c);

                    unseen_spotted = !c.is_seen;

                    if (!unseen_spotted) {

                        element.scrollIntoView({ behavior: "smooth" , block : 'center' });

                    }

                    unseen_spotted = !c.is_seen;

                });
            }, 200);


            all_chats_list.forEach((c, k) => {

                if (k !== this.room_id) {
                    c.closeChat();
                }

            })

        }

        pushMessage(message_item) {

            if (!message_item instanceof MessageItem) {
                show_toaster("Invalid message type");
                return;
            }

            message_item.parent_chat = this;

            this.chat.push(message_item);
            if (this.active) {

                var element = append_message_element(message_item);
                element.scrollIntoView({ behavior: "smooth" });
            }

            if (message_item.id) {
                id_to_message_cache.set(message_item.id, message_item);
            }

            this.refreshUnseenCounter();

        }
    };

    function connect() {

        socket = new ReconnectingWebSocket(`${socket_url}?authtoken=${auth_token}`);

        socket.onopen = function () {
            console.log('Connection open');
        }

        socket.onmessage = function (event) {

            let message = {};

            try {

                message = JSON.parse(event.data);

            } catch (error) {

                show_toaster("Invalid incoming message");
                return;

            }

            if (!message.event) {
                show_toaster("Invalid incoming message");
                return;
            }

            if (ChatStatics.EVENT_MESSAGE === message.event) {

                var room_id = message.data.m.room_id;
                if (room_id) {

                    if (all_chats_list.has(room_id)) {

                        var msg = new MessageItem(message.data.m);
                        all_chats_list.get(room_id).pushMessage(msg);

                    } else {

                        (async () => {

                            chat_list_item = create_user_list_item({ room_id });

                            await chat_list_item.loadChat();

                            all_chats_list.set(chat_list_item.room_id, chat_list_item)

                            user_list_container.append(chat_list_item.element);

                            room_id = chat_list_item.room_id;

                        })()

                    }

                }

            } else if (ChatStatics.EVENT_ACTION === message.event) {

                if (ChatStatics.STATUS_DELIVERED === message.data.type && temp_uuid_to_message.has(message.data.temp_uuid)) {

                    temp_uuid_to_message.get(message.data.temp_uuid).delivered(message.data);

                } else if (ChatStatics.STATUS_READ === message.data.type && id_to_message_cache.has(message.data.m_id)) {
                    id_to_message_cache.get(message.data.m_id).mark_as_read();
                }

            }






        }

        socket.onerror = function (event) {

        }

        socket.onclose = function (event) {


        }

        return socket;
    }

    async function open_chat(username) {

        let room_id;

        const open = async () => {

            let chat = all_chats_list.get(room_id);
            active_room_id = room_id;
        
            chat_window.classList.add('flex');
            chat_window.classList.remove('hidden');
            no_chat_selected.classList.add('hidden');
            no_chat_selected.classList.remove('flex');

            await chat.loadChat();
            chat.renderChat();

            message_input.focus();

        }

        await render_chat_list();

        if (username_to_room_id_cache.has(username) && all_chats_list.has(room_id = username_to_room_id_cache.get(username))) {

            open();

        } else {

            const endpoint = encodeURI(`${AJAX_ENDPOINT}/search-user?q=${username}&strict=1`);

            ajax_request(endpoint, {},
                async response => {

                    if (response.data?.length) {

                        chat_list_item = create_user_list_item({ username });

                        await chat_list_item.loadChat();

                        all_chats_list.set(chat_list_item.room_id, chat_list_item)

                        user_list_container.append(chat_list_item.element);

                        room_id = chat_list_item.room_id;

                        open();

                    } else {

                        window.history.back();

                    }

                },
                e => {
                    show_toaster(e, false);
                    window.history.back();
                }
            )

        }



    }

    function close_all_chats() {
            
        chat_window.classList.remove('flex');
        chat_window.classList.add('hidden');
        no_chat_selected.classList.remove('hidden');
        no_chat_selected.classList.add('flex');

        all_chats_list.forEach( c => c.closeChat() );

    }

    function render_chat_list() {

        return new Promise(resolve => {

            if (chat_list_loaded) {
                resolve(true);
                return;
            }

            user_list_container.textContent = '';
            for (let i = 0; i < 5; i++) {
                user_list_container.appendChild(create_user_list_skeleton());
            }

            setTimeout(() => {

                ajax_request(`${AJAX_ENDPOINT}/chat/chatlist`, {
                    headers: {
                        'AuthToken': auth_token
                    }
                }, (response) => {

                    if (!response.success) {
                        show_toaster(response.message)
                        resolve(false);
                        return;
                    }

                    user_list_container.textContent = '';
                    response.data.forEach(i => {

                        const chat_list_item = create_user_list_item(i);

                        all_chats_list.set(i.room_id, chat_list_item)

                        user_list_container.append(chat_list_item.element);

                        chat_list_item.loadChat();

                    })
                    chat_list_loaded = true;
                    resolve(true);

                }, e => {
                    show_toaster(e);
                    resolve(false);
                });

            }, 500);

        })


    }

    const ROUTES = {

        callback: () => {

            render_chat_list();

        },
        children: {

            chat: {

                callback: (path_array) => {

                    if (path_array[0]) {

                        open_chat(path_array[0]);

                    } else {

                        close_all_chats();

                    }

                }

            }

        }

    }

    function route_guide(path_array, routes) {

        const descendant = path_array.shift();
        if (descendant && routes.children?.[descendant]?.callback) {
            route_guide(path_array, routes.children[descendant])
        } else {
            routes.callback([descendant ?? '', ...path_array]);
        }

    }

    function hash_change_handler(hash) {

        hash = hash.replace('#', '');

        const hash_array = hash.split('/').filter(x => x.trim());

        route_guide(hash_array, ROUTES);

    }

    window.onhashchange = function (e) {

        e && e.preventDefault();

        hash_change_handler(location.hash);

    }


    render_chat_list()
        .then(() => {
            hash_change_handler(location.hash);
        });

    connect();

    message_input.addEventListener('input', function (e) {

        const lines = (String(this.value).match(/\n/g) || '').length + 1;
        this.rows = Math.max(Math.min(lines, 10), 2);

    });

    message_input.addEventListener('keydown', function (e) {

        if ((e.ctrlKey || e.metaKey) && e.key == 'Enter') {
            send_message?.click();
        }

    })

    chat_form.addEventListener('submit', function (e) {

        e.preventDefault();

        if (socket === null || socket.readyState != WebSocket.OPEN) {
            show_toaster("Connection failure");
            return;
        }

        const receiver = all_chats_list.get(active_room_id);

        if (!receiver) {
            show_toaster("Receiver doesn't exists");
            return;
        }

        const message = message_input.value.trim();

        if (!message) {
            return;
        }
        const temp_uuid = window.crypto.randomUUID();

        const message_object = new MessageItem({
            room_id: receiver.room_id,
            message: message,
            parent_chat: receiver,
            side: MessageItem.RHS,
            temp_uuid
        });

        message_object.send();
        message_input.value = '';
        message_input.focus();

    })

    search_users_popover.addEventListener('toggle', function (e) {

        'open' === e.newState && search_users_input.focus();

    })

    search_users_input.oninput = search_users_input.onchange = debounce(() => {

        const search_q = search_users_input.value.trim();

        if (!search_q) return;

        search_users_loader.setAttribute('data-loading', 1);

        const endpoint = encodeURI(`${AJAX_ENDPOINT}/search-user?q=` + search_q);

        ajax_request(endpoint, {},
            response => {

                search_users_loader.removeAttribute('data-loading');

                if (!response.success) return;

                search_users_result.innerHTML = '';

                response.data.forEach(user => {

                    const resultItem = create_users_search_list_item();

                    var link = resultItem.querySelector('[data-link]');
                    link.href = `#/chat/${user.username}`;
                    link.addEventListener('click', (e) => {
                        search_users_popover.hidePopover();
                    })

                    var pfp = resultItem.querySelector('[data-pfp]');
                    pfp.alt = user.username;
                    pfp.src = `${UPLOADS_PATH}/${user.profile_picture}`;

                    resultItem.querySelector('[data-username]').textContent = user.username;

                    search_users_result.appendChild(resultItem);

                })

            },
            e => {

                search_users_loader.removeAttribute('data-loading');
                show_toaster(e, false);

            }
        )

    }, 200);



    function append_message_element(message_obj) {

        let message = create_chat_message(message_obj.side);
        const main = message.firstElementChild;
        main.mark_as_read = () => {
            message_obj.read();
        }

        message_obj.element = main;

        message.querySelector('[data-message]').textContent = message_obj.message;
        const time_element = message.querySelector('[data-time]');

        time_element.setAttribute(RELATIVE_TIME_SELECTOR, '');
        time_element.setAttribute(RELATIVE_TIME_HOLDER, message_obj.sent_at);

        message_obj.setStatus();
    

        chat_messages_container.append(message);
        if (message_obj.side === MessageItem.LHS) {
            message_seen_observer.observe(main);
        }
        set_all_relative_times();
        return main;

    }

    function create_user_list_item(userdata) {

        const template = document.getElementById('user_template');
        const element = template.content.cloneNode(true);

        element.querySelector('[data-username]').textContent = userdata.username ?? "User";
        element.querySelector('[data-lastmessage]').textContent = userdata.latest_message ?? '';
        element.querySelector('[data-pfp]').style.backgroundImage = `url(${UPLOADS_PATH}/${userdata.profile_picture ?? NO_PROFILE_IMAGE})`;

        this_chat_item = new ChatItem({
            ...userdata,
            element: element.querySelector('[data-main]'),
        });

        element.querySelector('[data-item-link]').href = `#/chat/${userdata.username}`;

        return this_chat_item;
    }

    function create_chat_message(side) {

        const template = document.getElementById(side === MessageItem.LHS ? 'message_template_lhs' : 'message_template_rhs');
        const clone = template.content.cloneNode(true);

        return clone;
    }

    function create_user_list_skeleton() {
        const template = document.getElementById('user_template_skeleton');
        const clone = template.content.cloneNode(true);
        return clone;
    }

    function create_chat_message_skeleton(is_sent) {

        const template = document.getElementById('message_template_skeleton');
        const clone = template.content.cloneNode(true);
        if (is_sent) {
            clone.querySelector('[data-main').classList.remove('justify-start')
            clone.querySelector('[data-main').classList.add('justify-end')
        } else {
            clone.querySelector('[data-main').classList.add('justify-start')
            clone.querySelector('[data-main').classList.remove('justify-end')
        }
        return clone;
    }

    function create_users_search_list_item() {

        const template = document.getElementById('users_search_list_item');

        return template.content.cloneNode(true);

    }
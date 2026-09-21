/** @jsx h */
import { Fragment, h } from 'preact';
import { useCallback, useEffect, useState } from 'preact/hooks';
import { ChatPanel } from './components.jsx';
import { AgentAvatar } from './avatars.jsx';
import { TopicList } from './topic-list.jsx';
import { StartedList } from './started-list.jsx';
import { RoadmapList } from './roadmap-list.jsx';
import { ParticipatingList } from './participating-list.jsx';
import { TopicDetail } from './topic-detail.jsx';
import { PublishDialog } from './publish-dialog.jsx';
import { publishCase, submitTopic } from './api.js';
import { fetchTabCounts } from './tab-counts.js';
import {
  agentDisplayName, attribution, betaEnabled, chatEnabled, projectDisplayName,
} from './config.js';

const TABS = [
  { id: 'all', label: 'All', enabled: true },
  { id: 'participating', label: 'Participating', enabled: true },
  { id: 'started', label: 'Started', enabled: true },
  { id: 'roadmap', label: 'Roadmap', enabled: true },
];

export function CommunityApp() {
  const [resetNonce, setResetNonce] = useState(0);
  const [listRefresh, setListRefresh] = useState(0);
  const [activeTab, setActiveTab] = useState('all');
  const [view, setView] = useState('list');
  const [selectedTopic, setSelectedTopic] = useState(null);
  const [publishDraft, setPublishDraft] = useState(null);
  const [publishBusy, setPublishBusy] = useState(false);
  const [tabCounts, setTabCounts] = useState(null);
  const [chatOpen, setChatOpen] = useState(false);
  const [newTopicOpen, setNewTopicOpen] = useState(false);
  const [newTopicBusy, setNewTopicBusy] = useState(false);
  const [newTopicErrorKind, setNewTopicErrorKind] = useState(null);
  const chatOn = chatEnabled();
  const agentName = agentDisplayName();
  const projectName = projectDisplayName();
  const attributionText = attribution();

  useEffect(() => {
    let cancelled = false;
    (async () => {
      const counts = await fetchTabCounts();
      if (!cancelled) setTabCounts(counts);
    })();
    return () => { cancelled = true; };
  }, [listRefresh]);

  const requestNewTopic = () => {
    if (chatOn) {
      setResetNonce((n) => n + 1);
      setChatOpen(true);
    } else {
      setNewTopicErrorKind(null);
      setNewTopicOpen(true);
    }
  };
  const closeNewTopic = () => {
    setNewTopicOpen(false);
    setNewTopicErrorKind(null);
  };
  const openTopic = (topic) => {
    setSelectedTopic(topic);
    setView('detail');
  };
  const backToList = () => {
    setView('list');
    setSelectedTopic(null);
  };
  const bumpLists = () => setListRefresh((n) => n + 1);
  const refreshTabCountsOnly = useCallback(async () => {
    const counts = await fetchTabCounts();
    setTabCounts(counts);
  }, []);

  async function confirmPublish({ title, summary, type }) {
    if (!publishDraft?.id || publishBusy) return;
    setPublishBusy(true);
    const res = await publishCase({ case_id: publishDraft.id, title, summary, type });
    setPublishBusy(false);
    if (res.error) return;
    setPublishDraft(null);
    bumpLists();
    if (activeTab !== 'started') setActiveTab('started');
  }

  async function confirmDirectSubmit({ title, summary, type }) {
    if (newTopicBusy) return;
    setNewTopicBusy(true);
    setNewTopicErrorKind(null);
    const res = await submitTopic({ type, title, body: summary });
    setNewTopicBusy(false);
    if (res.error) {
      setNewTopicErrorKind(res.error.kind);
      return;
    }
    setNewTopicOpen(false);
    bumpLists();
    setActiveTab('started');
  }

  return (
    <div class={'rt-layout' + (chatOn && chatOpen ? ' rt-chat-open' : '')}>
      <main class="rt-main">
        {view === 'detail' && selectedTopic ? (
          <TopicDetail topic={selectedTopic} onBack={backToList} onVoteChange={refreshTabCountsOnly} />
        ) : (
          <div class="rt-list-shell">
            <div class="rt-pagehead">
              <div>
                <h1 class="rt-title">Community{betaEnabled() && <span class="rt-beta-pill">Beta</span>}</h1>
                {projectName && <p class="rt-sub">{projectName}</p>}
              </div>
              <button class="rt-newtopic-head" type="button" onClick={requestNewTopic}>+ New topic</button>
            </div>
            {attributionText && <div class="rt-banner rt-banner-info rt-attribution">{attributionText}</div>}
            <nav class="rt-tabs" aria-label="Community views">
              {TABS.map((tab) => (
                <button
                  key={tab.id}
                  type="button"
                  class={tab.id === activeTab ? 'on' : ''}
                  disabled={!tab.enabled}
                  title={tab.hint || undefined}
                  aria-disabled={!tab.enabled}
                  onClick={() => tab.enabled && setActiveTab(tab.id)}
                >
                  {tab.label}
                  {tabCounts && tabCounts[tab.id] != null && (
                    <span class="rt-tab-n">{tabCounts[tab.id]}</span>
                  )}
                </button>
              ))}
            </nav>
            {activeTab === 'all' && (
              <TopicList refreshNonce={listRefresh} onSelectTopic={openTopic} onVoteChange={refreshTabCountsOnly} />
            )}
            {activeTab === 'participating' && (
              <ParticipatingList refreshNonce={listRefresh} onSelectTopic={openTopic} onVoteChange={refreshTabCountsOnly} />
            )}
            {activeTab === 'started' && (
              <StartedList
                refreshNonce={listRefresh}
                onSelectTopic={openTopic}
                onReviewDraft={(topic) => setPublishDraft({ ...topic, summary: topic.snippet })}
                onVoteChange={refreshTabCountsOnly}
              />
            )}
            {activeTab === 'roadmap' && (
              <RoadmapList refreshNonce={listRefresh} onSelectTopic={openTopic} onVoteChange={refreshTabCountsOnly} />
            )}
          </div>
        )}
      </main>
      {chatOn && (
        <Fragment>
          {!chatOpen && (
            <button
              type="button"
              class="rt-chat-launch"
              aria-label={`Open ${agentName} chat`}
              onClick={() => setChatOpen(true)}
            >
              <AgentAvatar size="sm" />
            </button>
          )}
          {chatOpen && (
            <button
              type="button"
              class="rt-chat-backdrop"
              aria-label="Close chat"
              onClick={() => setChatOpen(false)}
            />
          )}
          <aside class={'rt-aside' + (chatOpen ? ' is-open' : '')}>
            <button
              type="button"
              class="rt-chat-close"
              aria-label="Close chat"
              onClick={() => setChatOpen(false)}
            >×</button>
            <ChatPanel
              resetNonce={resetNonce}
              onTopicPublished={() => { bumpLists(); setActiveTab('started'); }}
            />
          </aside>
        </Fragment>
      )}
      {publishDraft && (
        <PublishDialog
          draft={publishDraft}
          busy={publishBusy}
          onCancel={() => setPublishDraft(null)}
          onPublish={confirmPublish}
        />
      )}
      {newTopicOpen && (
        <PublishDialog
          variant="direct"
          busy={newTopicBusy}
          errorKind={newTopicErrorKind}
          onCancel={closeNewTopic}
          onPublish={confirmDirectSubmit}
        />
      )}
    </div>
  );
}
.PHONY: install
install:
	composer install

.PHONY: test
test:
	composer test

.PHONY: phpstan
phpstan:
	composer phpstan

.PHONY: ci
ci:
	composer ci

.PHONY: agent_learning_validate ## validate agent-learning findings, proposals, and history
agent_learning_validate:
	php bin/agent-learning validate --root infra/doc/agent-learning

.PHONY: agent_learning_prepare ## prepare consolidation input
agent_learning_prepare:
	@if [ -z "$(TASK)$(FINDING)$(SCOPE)$(ARGS)" ]; then \
		echo "❌ Missing selector parameter"; \
		echo "   Usage: make agent_learning_prepare TASK=ID"; \
		exit 1; \
	fi
	php bin/agent-learning prepare --root infra/doc/agent-learning $(if $(TASK),--task "$(TASK)") $(if $(FINDING),--finding "$(FINDING)") $(if $(SCOPE),--scope "$(SCOPE)") $(if $(SINCE),--since "$(SINCE)") $(if $(UNTIL),--until "$(UNTIL)") $(if $(OUTPUT),--output "$(OUTPUT)") $(ARGS)

.PHONY: agent_learning_proposal_validate ## validate one proposal
agent_learning_proposal_validate:
	@if [ -z "$(PROPOSAL)" ]; then \
		echo "❌ Missing PROPOSAL parameter"; \
		exit 1; \
	fi
	php bin/agent-learning proposal-validate --root infra/doc/agent-learning --proposal "$(PROPOSAL)"

.PHONY: agent_learning_proposal_import ## import a consolidation result file as a candidate proposal
agent_learning_proposal_import:
	@if [ -z "$(INPUT)" ]; then \
		echo "❌ Missing INPUT parameter"; \
		exit 1; \
	fi
	php bin/agent-learning proposal-import --root infra/doc/agent-learning --input "$(INPUT)"

.PHONY: agent_learning_finding_transition ## transition a finding status
agent_learning_finding_transition:
	@if [ -z "$(FINDING)" ] || [ -z "$(STATUS)" ] || [ -z "$(BY)" ]; then \
		echo "❌ Missing FINDING, STATUS, or BY parameter"; \
		exit 1; \
	fi
	php bin/agent-learning finding-transition --root infra/doc/agent-learning --by "$(BY)" "$(FINDING)" "$(STATUS)"

.PHONY: agent_learning_proposal_approve ## approve a candidate proposal
agent_learning_proposal_approve:
	@if [ -z "$(PROPOSAL)" ] || [ -z "$(BY)" ]; then \
		echo "❌ Missing PROPOSAL or BY parameter"; \
		exit 1; \
	fi
	php bin/agent-learning proposal-approve --root infra/doc/agent-learning --by "$(BY)" "$(PROPOSAL)"

.PHONY: agent_learning_proposal_reject ## reject a candidate proposal
agent_learning_proposal_reject:
	@if [ -z "$(PROPOSAL)" ] || [ -z "$(BY)" ] || [ -z "$(REASON)" ]; then \
		echo "❌ Missing PROPOSAL, BY, or REASON parameter"; \
		exit 1; \
	fi
	php bin/agent-learning proposal-reject --root infra/doc/agent-learning --by "$(BY)" --reason "$(REASON)" "$(PROPOSAL)"

.PHONY: agent_learning_proposal_mark_applied ## mark approved proposal applied
agent_learning_proposal_mark_applied:
	@if [ -z "$(PROPOSAL)" ] || [ -z "$(BY)" ] || [ -z "$(COMMIT)" ] || [ -z "$(VALIDATION)" ]; then \
		echo "❌ Missing PROPOSAL, BY, COMMIT, or VALIDATION parameter"; \
		exit 1; \
	fi
	php bin/agent-learning proposal-mark-applied --root infra/doc/agent-learning --by "$(BY)" --commit "$(COMMIT)" --validation "$(VALIDATION)" "$(PROPOSAL)"

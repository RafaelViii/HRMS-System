--
-- PostgreSQL database dump
--

\restrict ONhiVNlLefqdZKjALIjTe6fC3qnem8VxDdKkgTBHUaxlxi9FJZC7JJSowl00Vy0

-- Dumped from database version 17.5 (Ubuntu 17.5-1.pgdg20.04+1)
-- Dumped by pg_dump version 17.6

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: heroku_ext; Type: SCHEMA; Schema: -; Owner: postgres
--

CREATE SCHEMA heroku_ext;


ALTER SCHEMA heroku_ext OWNER TO postgres;

--
-- Name: public; Type: SCHEMA; Schema: -; Owner: uen9p9diua190r
--

-- *not* creating schema, since initdb creates it


ALTER SCHEMA public OWNER TO uen9p9diua190r;

--
-- Name: SCHEMA public; Type: COMMENT; Schema: -; Owner: uen9p9diua190r
--

COMMENT ON SCHEMA public IS '';


--
-- Name: pg_stat_statements; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pg_stat_statements WITH SCHEMA public;


--
-- Name: EXTENSION pg_stat_statements; Type: COMMENT; Schema: -; Owner: 
--

COMMENT ON EXTENSION pg_stat_statements IS 'track planning and execution statistics of all SQL statements executed';


--
-- Name: attendance_status; Type: TYPE; Schema: public; Owner: uen9p9diua190r
--

CREATE TYPE public.attendance_status AS ENUM (
    'present',
    'absent',
    'late',
    'on-leave',
    'holiday'
);


ALTER TYPE public.attendance_status OWNER TO uen9p9diua190r;

--
-- Name: doc_type; Type: TYPE; Schema: public; Owner: uen9p9diua190r
--

CREATE TYPE public.doc_type AS ENUM (
    'memo',
    'contract',
    'policy',
    'other'
);


ALTER TYPE public.doc_type OWNER TO uen9p9diua190r;

--
-- Name: employee_status; Type: TYPE; Schema: public; Owner: uen9p9diua190r
--

CREATE TYPE public.employee_status AS ENUM (
    'active',
    'terminated',
    'resigned',
    'on-leave'
);


ALTER TYPE public.employee_status OWNER TO uen9p9diua190r;

--
-- Name: employment_type; Type: TYPE; Schema: public; Owner: uen9p9diua190r
--

CREATE TYPE public.employment_type AS ENUM (
    'regular',
    'probationary',
    'contract',
    'part-time'
);


ALTER TYPE public.employment_type OWNER TO uen9p9diua190r;

--
-- Name: leave_action; Type: TYPE; Schema: public; Owner: uen9p9diua190r
--

CREATE TYPE public.leave_action AS ENUM (
    'approved',
    'rejected'
);


ALTER TYPE public.leave_action OWNER TO uen9p9diua190r;

--
-- Name: leave_request_status; Type: TYPE; Schema: public; Owner: uen9p9diua190r
--

CREATE TYPE public.leave_request_status AS ENUM (
    'pending',
    'approved',
    'rejected',
    'cancelled'
);


ALTER TYPE public.leave_request_status OWNER TO uen9p9diua190r;

--
-- Name: leave_type; Type: TYPE; Schema: public; Owner: uen9p9diua190r
--

CREATE TYPE public.leave_type AS ENUM (
    'sick',
    'vacation',
    'emergency',
    'unpaid',
    'other'
);


ALTER TYPE public.leave_type OWNER TO uen9p9diua190r;

--
-- Name: payroll_status; Type: TYPE; Schema: public; Owner: uen9p9diua190r
--

CREATE TYPE public.payroll_status AS ENUM (
    'open',
    'processed',
    'released'
);


ALTER TYPE public.payroll_status OWNER TO uen9p9diua190r;

--
-- Name: recruitment_status; Type: TYPE; Schema: public; Owner: uen9p9diua190r
--

CREATE TYPE public.recruitment_status AS ENUM (
    'new',
    'shortlist',
    'interviewed',
    'hired',
    'rejected'
);


ALTER TYPE public.recruitment_status OWNER TO uen9p9diua190r;

--
-- Name: user_role; Type: TYPE; Schema: public; Owner: uen9p9diua190r
--

CREATE TYPE public.user_role AS ENUM (
    'admin',
    'hr',
    'employee',
    'accountant',
    'manager',
    'hr_supervisor',
    'hr_recruit',
    'hr_payroll',
    'admin_assistant'
);


ALTER TYPE public.user_role OWNER TO uen9p9diua190r;

--
-- Name: user_status; Type: TYPE; Schema: public; Owner: uen9p9diua190r
--

CREATE TYPE public.user_status AS ENUM (
    'active',
    'inactive'
);


ALTER TYPE public.user_status OWNER TO uen9p9diua190r;

--
-- Name: fn_roles_meta_set_updated(); Type: FUNCTION; Schema: public; Owner: uen9p9diua190r
--

CREATE FUNCTION public.fn_roles_meta_set_updated() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  NEW.updated_at = CURRENT_TIMESTAMP;
  RETURN NEW;
END;
$$;


ALTER FUNCTION public.fn_roles_meta_set_updated() OWNER TO uen9p9diua190r;

--
-- Name: set_updated_at(); Type: FUNCTION; Schema: public; Owner: uen9p9diua190r
--

CREATE FUNCTION public.set_updated_at() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  NEW.updated_at = NOW();
  RETURN NEW;
END;
$$;


ALTER FUNCTION public.set_updated_at() OWNER TO uen9p9diua190r;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: access_template_permissions; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.access_template_permissions (
    template_id integer NOT NULL,
    module character varying(100) NOT NULL,
    level character varying(20) NOT NULL,
    CONSTRAINT chk_atp_level CHECK (((level)::text = ANY ((ARRAY['none'::character varying, 'read'::character varying, 'write'::character varying, 'admin'::character varying])::text[])))
);


ALTER TABLE public.access_template_permissions OWNER TO uen9p9diua190r;

--
-- Name: access_templates; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.access_templates (
    id integer NOT NULL,
    name character varying(100) NOT NULL,
    description character varying(255),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.access_templates OWNER TO uen9p9diua190r;

--
-- Name: access_templates_id_seq; Type: SEQUENCE; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE public.access_templates ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.access_templates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: action_reversals; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.action_reversals (
    id integer NOT NULL,
    audit_log_id integer NOT NULL,
    reversed_by integer NOT NULL,
    reason character varying(255),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.action_reversals OWNER TO uen9p9diua190r;

--
-- Name: action_reversals_id_seq; Type: SEQUENCE; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE public.action_reversals ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.action_reversals_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: attendance; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.attendance (
    id integer NOT NULL,
    employee_id integer NOT NULL,
    date date NOT NULL,
    time_in time without time zone,
    time_out time without time zone,
    overtime_minutes integer DEFAULT 0 NOT NULL,
    status public.attendance_status DEFAULT 'present'::public.attendance_status,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.attendance OWNER TO uen9p9diua190r;

--
-- Name: attendance_id_seq; Type: SEQUENCE; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE public.attendance ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.attendance_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: audit_logs; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.audit_logs (
    id integer NOT NULL,
    user_id integer,
    action character varying(191) NOT NULL,
    details text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    details_raw text
);


ALTER TABLE public.audit_logs OWNER TO uen9p9diua190r;

--
-- Name: audit_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE public.audit_logs ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.audit_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: departments; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.departments (
    id integer NOT NULL,
    name character varying(191) NOT NULL,
    description text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.departments OWNER TO uen9p9diua190r;

--
-- Name: departments_backup; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.departments_backup (
    id integer NOT NULL,
    name character varying(191) NOT NULL,
    description text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.departments_backup OWNER TO uen9p9diua190r;

--
-- Name: departments_backup_id_seq; Type: SEQUENCE; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE public.departments_backup ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.departments_backup_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    MAXVALUE 2147483647
    CACHE 1
);


--
-- Name: departments_id_seq; Type: SEQUENCE; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE public.departments ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.departments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: document_assignments; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.document_assignments (
    id integer NOT NULL,
    document_id integer NOT NULL,
    employee_id integer,
    department_id integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.document_assignments OWNER TO uen9p9diua190r;

--
-- Name: document_assignments_id_seq; Type: SEQUENCE; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE public.document_assignments ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.document_assignments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: documents; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.documents (
    id integer NOT NULL,
    title character varying(191) NOT NULL,
    doc_type public.doc_type DEFAULT 'memo'::public.doc_type NOT NULL,
    file_path character varying(255) NOT NULL,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.documents OWNER TO uen9p9diua190r;

--
-- Name: documents_id_seq; Type: SEQUENCE; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE public.documents ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.documents_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: employees; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.employees (
    id integer NOT NULL,
    user_id integer,
    employee_code character varying(50) NOT NULL,
    first_name character varying(100) NOT NULL,
    last_name character varying(100) NOT NULL,
    email character varying(191) NOT NULL,
    phone character varying(50),
    address text,
    department_id integer,
    position_id integer,
    hire_date date,
    employment_type public.employment_type DEFAULT 'regular'::public.employment_type,
    status public.employee_status DEFAULT 'active'::public.employee_status,
    salary numeric(12,2) DEFAULT 0 NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.employees OWNER TO uen9p9diua190r;

--
-- Name: employees_backup; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.employees_backup (
    id integer NOT NULL,
    user_id integer,
    employee_code character varying(50) NOT NULL,
    first_name character varying(100) NOT NULL,
    last_name character varying(100) NOT NULL,
    email character varying(191) NOT NULL,
    phone character varying(50),
    address text,
    department_id integer,
    position_id integer,
    hire_date date,
    employment_type public.employment_type DEFAULT 'regular'::public.employment_type,
    status public.employee_status DEFAULT 'active'::public.employee_status,
    salary numeric(12,2) DEFAULT 0 NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.employees_backup OWNER TO uen9p9diua190r;

--
-- Name: employees_backup_id_seq; Type: SEQUENCE; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE public.employees_backup ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.employees_backup_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    MAXVALUE 2147483647
    CACHE 1
);


--
-- Name: employees_id_seq; Type: SEQUENCE; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE public.employees ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.employees_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: leave_request_actions; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.leave_request_actions (
    id integer NOT NULL,
    leave_request_id integer NOT NULL,
    action public.leave_action NOT NULL,
    reason text,
    acted_by integer,
    acted_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.leave_request_actions OWNER TO uen9p9diua190r;

--
-- Name: leave_request_actions_id_seq; Type: SEQUENCE; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE public.leave_request_actions ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.leave_request_actions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: leave_requests; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.leave_requests (
    id integer NOT NULL,
    employee_id integer NOT NULL,
    leave_type public.leave_type NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL,
    total_days numeric(5,2) NOT NULL,
    status public.leave_request_status DEFAULT 'pending'::public.leave_request_status NOT NULL,
    remarks text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.leave_requests OWNER TO uen9p9diua190r;

--
-- Name: leave_requests_backup; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.leave_requests_backup (
    id integer NOT NULL,
    employee_id integer NOT NULL,
    leave_type public.leave_type NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL,
    total_days numeric(5,2) NOT NULL,
    status public.leave_request_status DEFAULT 'pending'::public.leave_request_status NOT NULL,
    remarks text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.leave_requests_backup OWNER TO uen9p9diua190r;

--
-- Name: leave_requests_backup_id_seq; Type: SEQUENCE; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE public.leave_requests_backup ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.leave_requests_backup_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    MAXVALUE 2147483647
    CACHE 1
);


--
-- Name: leave_requests_id_seq; Type: SEQUENCE; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE public.leave_requests ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.leave_requests_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: notification_reads; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.notification_reads (
    notification_id integer NOT NULL,
    user_id integer NOT NULL,
    read_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.notification_reads OWNER TO uen9p9diua190r;

--
-- Name: notifications; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.notifications (
    id integer NOT NULL,
    user_id integer,
    message character varying(255) NOT NULL,
    is_read boolean DEFAULT false NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.notifications OWNER TO uen9p9diua190r;

--
-- Name: notifications_id_seq; Type: SEQUENCE; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE public.notifications ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.notifications_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: payroll; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.payroll (
    id integer NOT NULL,
    employee_id integer NOT NULL,
    period_id integer NOT NULL,
    basic_pay numeric(12,2) DEFAULT 0 NOT NULL,
    allowances numeric(12,2) DEFAULT 0 NOT NULL,
    deductions numeric(12,2) DEFAULT 0 NOT NULL,
    net_pay numeric(12,2) DEFAULT 0 NOT NULL,
    released_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.payroll OWNER TO uen9p9diua190r;

--
-- Name: payroll_backup; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.payroll_backup (
    id integer NOT NULL,
    employee_id integer NOT NULL,
    period_id integer NOT NULL,
    basic_pay numeric(12,2) DEFAULT 0 NOT NULL,
    allowances numeric(12,2) DEFAULT 0 NOT NULL,
    deductions numeric(12,2) DEFAULT 0 NOT NULL,
    net_pay numeric(12,2) DEFAULT 0 NOT NULL,
    released_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.payroll_backup OWNER TO uen9p9diua190r;

--
-- Name: payroll_backup_id_seq; Type: SEQUENCE; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE public.payroll_backup ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.payroll_backup_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    MAXVALUE 2147483647
    CACHE 1
);


--
-- Name: payroll_id_seq; Type: SEQUENCE; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE public.payroll ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.payroll_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: payroll_periods; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.payroll_periods (
    id integer NOT NULL,
    period_start date NOT NULL,
    period_end date NOT NULL,
    status public.payroll_status DEFAULT 'open'::public.payroll_status,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.payroll_periods OWNER TO uen9p9diua190r;

--
-- Name: payroll_periods_id_seq; Type: SEQUENCE; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE public.payroll_periods ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.payroll_periods_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: pdf_templates; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.pdf_templates (
    id integer NOT NULL,
    report_key character varying(100) NOT NULL,
    settings jsonb NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.pdf_templates OWNER TO uen9p9diua190r;

--
-- Name: pdf_templates_id_seq; Type: SEQUENCE; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE public.pdf_templates ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.pdf_templates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: performance_reviews; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.performance_reviews (
    id integer NOT NULL,
    employee_id integer NOT NULL,
    review_date date NOT NULL,
    kpi_score numeric(5,2) DEFAULT 0 NOT NULL,
    remarks text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.performance_reviews OWNER TO uen9p9diua190r;

--
-- Name: performance_reviews_id_seq; Type: SEQUENCE; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE public.performance_reviews ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.performance_reviews_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: positions; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.positions (
    id integer NOT NULL,
    department_id integer,
    name character varying(191) NOT NULL,
    description text,
    base_salary numeric(12,2) DEFAULT 0 NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.positions OWNER TO uen9p9diua190r;

--
-- Name: positions_backup; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.positions_backup (
    id integer NOT NULL,
    department_id integer,
    name character varying(191) NOT NULL,
    description text,
    base_salary numeric(12,2) DEFAULT 0 NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.positions_backup OWNER TO uen9p9diua190r;

--
-- Name: positions_backup_id_seq; Type: SEQUENCE; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE public.positions_backup ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.positions_backup_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    MAXVALUE 2147483647
    CACHE 1
);


--
-- Name: positions_id_seq; Type: SEQUENCE; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE public.positions ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.positions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: recruitment; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.recruitment (
    id integer NOT NULL,
    full_name character varying(191) NOT NULL,
    email character varying(191),
    phone character varying(50),
    position_applied character varying(191),
    template_id integer,
    converted_employee_id integer,
    resume_path character varying(255),
    status public.recruitment_status DEFAULT 'new'::public.recruitment_status,
    notes text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.recruitment OWNER TO uen9p9diua190r;

--
-- Name: recruitment_files; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.recruitment_files (
    id integer NOT NULL,
    recruitment_id integer NOT NULL,
    label character varying(100) NOT NULL,
    file_path character varying(255) NOT NULL,
    uploaded_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.recruitment_files OWNER TO uen9p9diua190r;

--
-- Name: recruitment_files_id_seq; Type: SEQUENCE; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE public.recruitment_files ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.recruitment_files_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: recruitment_id_seq; Type: SEQUENCE; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE public.recruitment ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.recruitment_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: recruitment_template_fields; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.recruitment_template_fields (
    template_id integer NOT NULL,
    field_name character varying(50) NOT NULL,
    is_required smallint DEFAULT 1 NOT NULL
);


ALTER TABLE public.recruitment_template_fields OWNER TO uen9p9diua190r;

--
-- Name: recruitment_template_files; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.recruitment_template_files (
    template_id integer NOT NULL,
    label character varying(100) NOT NULL,
    is_required smallint DEFAULT 1 NOT NULL
);


ALTER TABLE public.recruitment_template_files OWNER TO uen9p9diua190r;

--
-- Name: recruitment_templates; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.recruitment_templates (
    id integer NOT NULL,
    name character varying(191) NOT NULL,
    description character varying(255),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.recruitment_templates OWNER TO uen9p9diua190r;

--
-- Name: recruitment_templates_id_seq; Type: SEQUENCE; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE public.recruitment_templates ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.recruitment_templates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: roles_meta; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.roles_meta (
    role_name character varying(100) NOT NULL,
    label character varying(150) NOT NULL,
    description text,
    is_active smallint DEFAULT 1 NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.roles_meta OWNER TO uen9p9diua190r;

--
-- Name: roles_meta_permissions; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.roles_meta_permissions (
    role_name character varying(100) NOT NULL,
    module character varying(100) NOT NULL,
    level character varying(20) NOT NULL,
    CONSTRAINT roles_meta_permissions_level_check CHECK (((level)::text = ANY ((ARRAY['none'::character varying, 'read'::character varying, 'write'::character varying, 'admin'::character varying])::text[])))
);


ALTER TABLE public.roles_meta_permissions OWNER TO uen9p9diua190r;

--
-- Name: schema_migrations; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.schema_migrations (
    filename character varying(255) NOT NULL,
    checksum character varying(64) NOT NULL,
    applied_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.schema_migrations OWNER TO uen9p9diua190r;

--
-- Name: system_logs; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.system_logs (
    id integer NOT NULL,
    code character varying(20) NOT NULL,
    message text NOT NULL,
    module character varying(100),
    file character varying(255),
    line integer,
    func character varying(100),
    context text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.system_logs OWNER TO uen9p9diua190r;

--
-- Name: system_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE public.system_logs ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.system_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: user_access_permissions; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.user_access_permissions (
    user_id integer NOT NULL,
    module character varying(100) NOT NULL,
    level character varying(20) NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_uap_level CHECK (((level)::text = ANY ((ARRAY['none'::character varying, 'read'::character varying, 'write'::character varying, 'admin'::character varying])::text[])))
);


ALTER TABLE public.user_access_permissions OWNER TO uen9p9diua190r;

--
-- Name: users; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.users (
    id integer NOT NULL,
    email character varying(191) NOT NULL,
    password_hash character varying(255) NOT NULL,
    full_name character varying(191) NOT NULL,
    role public.user_role DEFAULT 'employee'::public.user_role NOT NULL,
    status public.user_status DEFAULT 'active'::public.user_status NOT NULL,
    last_login timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.users OWNER TO uen9p9diua190r;

--
-- Name: users_backup; Type: TABLE; Schema: public; Owner: uen9p9diua190r
--

CREATE TABLE public.users_backup (
    id integer NOT NULL,
    email character varying(191) NOT NULL,
    password_hash character varying(255) NOT NULL,
    full_name character varying(191) NOT NULL,
    role public.user_role DEFAULT 'employee'::public.user_role NOT NULL,
    status public.user_status DEFAULT 'active'::public.user_status NOT NULL,
    last_login timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.users_backup OWNER TO uen9p9diua190r;

--
-- Name: users_backup_id_seq; Type: SEQUENCE; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE public.users_backup ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.users_backup_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    MAXVALUE 2147483647
    CACHE 1
);


--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE public.users ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Data for Name: access_template_permissions; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.access_template_permissions (template_id, module, level) FROM stdin;
1	employees	read
1	attendance	read
1	documents	read
2	hr	admin
2	employees	admin
2	attendance	admin
2	payroll	admin
2	recruitment	admin
2	documents	admin
2	reports	admin
2	settings	admin
4	hr	write
4	employees	write
4	attendance	write
4	payroll	write
4	recruitment	write
4	documents	write
4	reports	write
4	settings	admin
5	hr	write
5	employees	write
5	attendance	read
5	payroll	write
5	recruitment	admin
5	documents	write
5	reports	write
5	settings	none
6	hr	none
6	employees	none
6	attendance	none
6	payroll	none
6	recruitment	none
6	documents	none
6	reports	none
6	settings	none
\.


--
-- Data for Name: access_templates; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.access_templates (id, name, description, created_at) FROM stdin;
1	Default Employee	Basic read access to personal modules	2025-09-05 15:37:49.890871
2	System Administrator	Overlord	2025-09-06 06:35:06.863732
4	System Administrator Assistant		2025-09-06 06:35:43.540626
5	HR Hiring		2025-09-06 06:36:56.793164
6	IT Employee		2025-09-06 06:37:33.37327
\.


--
-- Data for Name: action_reversals; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.action_reversals (id, audit_log_id, reversed_by, reason, created_at) FROM stdin;
\.


--
-- Data for Name: attendance; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.attendance (id, employee_id, date, time_in, time_out, overtime_minutes, status, created_at, updated_at) FROM stdin;
1	5	2025-09-12	07:18:00	19:18:00	240	present	2025-09-11 23:18:23.750064	2025-09-11 23:18:23.750064
2	11	2025-09-12	07:18:00	19:18:00	240	present	2025-09-11 23:18:56.520338	2025-09-11 23:18:56.520338
3	2	2025-09-18	08:16:00	20:16:00	240	present	2025-09-18 00:16:12.235225	2025-09-18 00:16:12.235225
\.


--
-- Data for Name: audit_logs; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.audit_logs (id, user_id, action, details, created_at, updated_at, details_raw) FROM stdin;
2	1	login	User logged in	2025-09-05 15:38:50.993509	2025-09-06 07:05:50.479594	User logged in
3	1	create_department	IT Department	2025-09-05 15:53:29.668906	2025-09-06 07:05:50.479594	IT Department
4	1	create_employee	IT001 Daniel Bobis	2025-09-05 15:57:42.964331	2025-09-06 07:05:50.479594	IT001 Daniel Bobis
5	1	create_position	System Administrator	2025-09-05 15:58:00.44492	2025-09-06 07:05:50.479594	System Administrator
6	1	login	User logged in	2025-09-05 16:18:25.757884	2025-09-06 07:05:50.479594	User logged in
9	1	login	User logged in	2025-09-05 16:25:00.794955	2025-09-06 07:05:50.479594	User logged in
11	1	login	User logged in	2025-09-05 16:27:37.895529	2025-09-06 07:05:50.479594	User logged in
12	\N	login.failed	Failed login for alop.gregjohnpaulbscs2023@gmail.com	2025-09-05 16:29:58.170655	2025-09-06 07:05:50.479594	Failed login for alop.gregjohnpaulbscs2023@gmail.com
13	\N	login.failed	Failed login for alop.gregjohnpaulbscs2023@gmail.com	2025-09-05 16:30:00.067222	2025-09-06 07:05:50.479594	Failed login for alop.gregjohnpaulbscs2023@gmail.com
14	\N	login.failed	Failed login for alop.gregjohnpaulbscs2023@gmail.com	2025-09-05 16:30:03.933379	2025-09-06 07:05:50.479594	Failed login for alop.gregjohnpaulbscs2023@gmail.com
15	\N	login.failed	Failed login for alop.gregjohnpaulbscs2023@gmail.com	2025-09-05 16:30:05.258864	2025-09-06 07:05:50.479594	Failed login for alop.gregjohnpaulbscs2023@gmail.com
16	\N	login.failed	Failed login for alop.gregjohnpaulbscs2023@gmail.com	2025-09-05 16:30:12.227041	2025-09-06 07:05:50.479594	Failed login for alop.gregjohnpaulbscs2023@gmail.com
17	1	login	User logged in	2025-09-05 16:34:46.183828	2025-09-06 07:05:50.479594	User logged in
18	1	login	User logged in	2025-09-05 16:41:47.751144	2025-09-06 07:05:50.479594	User logged in
19	1	login	User logged in	2025-09-05 16:41:58.543846	2025-09-06 07:05:50.479594	User logged in
20	1	login	User logged in	2025-09-05 17:01:51.860919	2025-09-06 07:05:50.479594	User logged in
21	1	login	User logged in	2025-09-06 05:54:22.413138	2025-09-06 07:05:50.479594	User logged in
22	1	export_pdf	positions	2025-09-06 05:57:47.084472	2025-09-06 07:05:50.479594	positions
23	1	export_pdf	positions	2025-09-06 05:57:48.105214	2025-09-06 07:05:50.479594	positions
7	1	create_account	{"user_id":3}	2025-09-05 16:21:34.881486	2025-09-06 07:05:50.753103	user_id=3
8	1	delete_account	{"user_id":3}	2025-09-05 16:21:49.349219	2025-09-06 07:05:50.753103	user_id=3
10	1	create_account	{"user_id":4}	2025-09-05 16:27:08.767149	2025-09-06 07:05:50.753103	user_id=4
24	1	update_access_permissions	{"user_id":4}	2025-09-06 05:59:08.208553	2025-09-06 07:05:50.753103	user_id=4
25	1	login	User logged in	2025-09-06 06:08:10.254763	2025-09-06 07:05:50.479594	User logged in
26	1	login	User logged in	2025-09-06 06:12:16.363934	2025-09-06 07:05:50.479594	User logged in
27	1	login	User logged in	2025-09-06 06:16:54.594035	2025-09-06 07:05:50.479594	User logged in
29	1	login	User logged in	2025-09-06 06:19:43.938344	2025-09-06 07:05:50.479594	User logged in
30	1	create_department	HR Department	2025-09-06 06:22:46.959377	2025-09-06 07:05:50.479594	HR Department
31	1	create_department	Engineering Departmet	2025-09-06 06:23:22.981535	2025-09-06 07:05:50.479594	Engineering Departmet
32	1	create_department	Operation Management	2025-09-06 06:24:42.193901	2025-09-06 07:05:50.479594	Operation Management
33	1	create_position	HR Supervisor	2025-09-06 06:25:15.442887	2025-09-06 07:05:50.479594	HR Supervisor
34	1	create_position	HR Payroll	2025-09-06 06:25:33.097783	2025-09-06 07:05:50.479594	HR Payroll
35	1	create_position	HR Hiring	2025-09-06 06:25:49.133486	2025-09-06 07:05:50.479594	HR Hiring
36	1	create_position	System Administrator Assistant	2025-09-06 06:27:01.496952	2025-09-06 07:05:50.479594	System Administrator Assistant
37	1	update_position	HR Hiring	2025-09-06 06:27:12.253566	2025-09-06 07:05:50.479594	HR Hiring
39	1	login	{"event":"login","ip":"136.158.42.116","ua":"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36"}	2025-09-06 06:39:27.360572	2025-09-06 07:05:50.479594	{"event":"login","ip":"136.158.42.116","ua":"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36"}
40	1	update_employee	IT001	2025-09-06 06:40:17.458559	2025-09-06 07:05:50.479594	IT001
42	5	login	{"event":"login","ip":"136.158.42.116","ua":"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36"}	2025-09-06 06:41:01.713209	2025-09-06 07:05:50.479594	{"event":"login","ip":"136.158.42.116","ua":"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36"}
43	1	logout	User logged out	2025-09-06 06:44:38.200371	2025-09-06 07:05:50.479594	User logged out
44	1	login	{"event":"login","ip":"136.158.42.116","ua":"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36"}	2025-09-06 06:44:41.516411	2025-09-06 07:05:50.479594	{"event":"login","ip":"136.158.42.116","ua":"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36"}
45	1	login	{"event":"login","ip":"136.158.42.116","ua":"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36"}	2025-09-06 06:47:25.06056	2025-09-06 07:05:50.479594	{"event":"login","ip":"136.158.42.116","ua":"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36"}
46	5	login	{"event":"login","ip":"136.158.42.116","ua":"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36"}	2025-09-06 06:48:01.569458	2025-09-06 07:05:50.479594	{"event":"login","ip":"136.158.42.116","ua":"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36"}
47	5	create_department	Accounting Department	2025-09-06 06:48:22.146848	2025-09-06 07:05:50.479594	Accounting Department
48	1	view_roles	{"module":"roles","status":"success","meta":{}}	2025-09-06 06:48:51.725115	2025-09-06 07:05:50.479594	{"module":"roles","status":"success","meta":{}}
49	1	view_roles	{"module":"roles","status":"success","meta":{}}	2025-09-06 06:50:14.653695	2025-09-06 07:05:50.479594	{"module":"roles","status":"success","meta":{}}
50	1	view_roles	{"module":"roles","status":"success","meta":{}}	2025-09-06 06:52:13.849516	2025-09-06 07:05:50.479594	{"module":"roles","status":"success","meta":{}}
51	1	login	{"event":"login","ip":"136.158.42.116","ua":"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36"}	2025-09-06 06:57:11.07432	2025-09-06 07:05:50.479594	{"event":"login","ip":"136.158.42.116","ua":"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36"}
53	1	view_roles	{"module":"roles","status":"success","meta":{}}	2025-09-06 07:02:38.237351	2025-09-06 07:05:50.479594	{"module":"roles","status":"success","meta":{}}
28	1	delete_account	{"user_id":4}	2025-09-06 06:17:14.985036	2025-09-06 07:05:50.753103	user_id=4
38	1	update_department	{"id":3}	2025-09-06 06:28:00.620473	2025-09-06 07:05:50.753103	id=3
41	1	create_account	{"user_id":5}	2025-09-06 06:40:46.201906	2025-09-06 07:05:50.753103	user_id=5
52	1	apply_role_defaults	{"user_id":5}	2025-09-06 07:01:03.919586	2025-09-06 07:05:50.753103	user_id=5
54	1	login	{"event":"login","ip":"136.158.42.116","ua":"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36"}	2025-09-06 07:07:40.68703	2025-09-06 07:07:40.68703	\N
55	1	update_access_permissions	user_id=1	2025-09-06 07:08:19.149826	2025-09-06 07:08:19.149826	\N
56	1	update_access_permissions	{"module":"account","status":"success","meta":{"user_id":1}}	2025-09-06 07:08:19.152572	2025-09-06 07:08:19.152572	\N
57	1	apply_role_defaults	user_id=1	2025-09-06 07:08:24.36258	2025-09-06 07:08:24.36258	\N
58	1	apply_role_defaults	{"module":"account","status":"success","meta":{"user_id":1}}	2025-09-06 07:08:24.36549	2025-09-06 07:08:24.36549	\N
59	1	update_access_permissions	user_id=1	2025-09-06 07:08:37.90342	2025-09-06 07:08:37.90342	\N
60	1	update_access_permissions	{"module":"account","status":"success","meta":{"user_id":1}}	2025-09-06 07:08:37.906333	2025-09-06 07:08:37.906333	\N
61	1	login	{"event":"login","ip":"136.158.42.116","ua":"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36"}	2025-09-06 08:21:34.004392	2025-09-06 08:21:34.004392	\N
62	1	create_employee	IT002 Gero Earl Pereyra	2025-09-06 08:22:37.089	2025-09-06 08:22:37.089	\N
63	1	update_employee	IT002	2025-09-06 08:23:01.476722	2025-09-06 08:23:01.476722	\N
64	1	update_employee	IT002	2025-09-06 08:23:57.045022	2025-09-06 08:23:57.045022	\N
65	1	update_employee	IT002	2025-09-06 08:24:21.920268	2025-09-06 08:24:21.920268	\N
66	1	create_account	user_id=6	2025-09-06 08:25:08.031227	2025-09-06 08:25:08.031227	\N
67	1	update_access_permissions	user_id=6	2025-09-06 08:25:34.547525	2025-09-06 08:25:34.547525	\N
68	1	update_access_permissions	{"module":"account","status":"success","meta":{"user_id":6}}	2025-09-06 08:25:34.549632	2025-09-06 08:25:34.549632	\N
69	6	login	{"event":"login","ip":"136.158.42.116","ua":"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36"}	2025-09-06 08:25:53.554525	2025-09-06 08:25:53.554525	\N
70	1	apply_role_defaults	user_id=6	2025-09-06 08:26:10.675558	2025-09-06 08:26:10.675558	\N
71	1	apply_role_defaults	{"module":"account","status":"success","meta":{"user_id":6}}	2025-09-06 08:26:10.677684	2025-09-06 08:26:10.677684	\N
72	1	update_access_permissions	user_id=6	2025-09-06 08:26:18.857787	2025-09-06 08:26:18.857787	\N
73	1	update_access_permissions	{"module":"account","status":"success","meta":{"user_id":6}}	2025-09-06 08:26:18.85999	2025-09-06 08:26:18.85999	\N
74	1	apply_role_defaults	user_id=6	2025-09-06 08:26:23.765916	2025-09-06 08:26:23.765916	\N
75	1	apply_role_defaults	{"module":"account","status":"success","meta":{"user_id":6}}	2025-09-06 08:26:23.768211	2025-09-06 08:26:23.768211	\N
76	1	create_employee	IT003 Greg John Paul Alop	2025-09-06 08:36:01.405853	2025-09-06 08:36:01.405853	\N
77	1	create_account	user_id=7	2025-09-06 08:38:26.254552	2025-09-06 08:38:26.254552	\N
78	1	update_access_permissions	user_id=7	2025-09-06 08:41:06.551782	2025-09-06 08:41:06.551782	\N
79	1	update_access_permissions	{"module":"account","status":"success","meta":{"user_id":7}}	2025-09-06 08:41:06.555008	2025-09-06 08:41:06.555008	\N
80	1	logout	User logged out	2025-09-06 08:47:59.796477	2025-09-06 08:47:59.796477	\N
81	\N	login.failed	Failed login for velasco.raeneilbscs2023@gmail.com	2025-09-06 10:19:28.13227	2025-09-06 10:19:28.13227	\N
82	1	login	{"event":"login","ip":"112.203.46.45","ua":"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36 Edg/139.0.0.0"}	2025-09-06 14:22:16.470753	2025-09-06 14:22:16.470753	\N
83	1	create_employee	HR001 Raeneil Velasco	2025-09-06 14:27:01.061599	2025-09-06 14:27:01.061599	\N
84	1	create_account	user_id=8	2025-09-06 14:27:21.799919	2025-09-06 14:27:21.799919	\N
85	1	create_employee	HR002 Mark Andrei Merto	2025-09-06 14:28:03.521778	2025-09-06 14:28:03.521778	\N
86	1	create_account	user_id=9	2025-09-06 14:28:13.606858	2025-09-06 14:28:13.606858	\N
87	1	update_account	user_id=9	2025-09-06 14:28:22.596782	2025-09-06 14:28:22.596782	\N
88	1	update_account	{"module":"account","status":"success","meta":{"user_id":9}}	2025-09-06 14:28:22.599903	2025-09-06 14:28:22.599903	\N
89	1	create_employee	HR003 Karylle De Torres	2025-09-06 14:29:12.063203	2025-09-06 14:29:12.063203	\N
90	1	create_account	user_id=10	2025-09-06 14:29:28.749384	2025-09-06 14:29:28.749384	\N
91	1	update_account	user_id=9	2025-09-06 14:29:50.381178	2025-09-06 14:29:50.381178	\N
92	1	update_account	{"module":"account","status":"success","meta":{"user_id":9}}	2025-09-06 14:29:50.383064	2025-09-06 14:29:50.383064	\N
93	1	update_account	user_id=9	2025-09-06 14:30:25.873816	2025-09-06 14:30:25.873816	\N
94	1	update_account	{"module":"account","status":"success","meta":{"user_id":9}}	2025-09-06 14:30:25.875689	2025-09-06 14:30:25.875689	\N
95	1	create_employee	HR004 Jhon Carlos Bagaforo	2025-09-06 14:31:19.470199	2025-09-06 14:31:19.470199	\N
96	1	create_account	user_id=11	2025-09-06 14:32:20.014457	2025-09-06 14:32:20.014457	\N
97	1	update_account	user_id=11	2025-09-06 14:32:30.82766	2025-09-06 14:32:30.82766	\N
98	1	update_account	{"module":"account","status":"success","meta":{"user_id":11}}	2025-09-06 14:32:30.82972	2025-09-06 14:32:30.82972	\N
99	1	create_employee	HR005 Stephanie Cueto	2025-09-06 14:33:55.384571	2025-09-06 14:33:55.384571	\N
100	1	create_account	user_id=12	2025-09-06 14:34:10.29227	2025-09-06 14:34:10.29227	\N
101	1	create_employee	HR006 Mark Joseph Buban	2025-09-06 14:34:56.99031	2025-09-06 14:34:56.99031	\N
102	1	create_account	user_id=13	2025-09-06 14:35:09.07104	2025-09-06 14:35:09.07104	\N
103	1	update_account	user_id=13	2025-09-06 14:35:15.700249	2025-09-06 14:35:15.700249	\N
104	1	update_account	{"module":"account","status":"success","meta":{"user_id":13}}	2025-09-06 14:35:15.702051	2025-09-06 14:35:15.702051	\N
105	1	create_employee	HR007 Mark Clent Bigayan	2025-09-06 14:36:07.189424	2025-09-06 14:36:07.189424	\N
106	1	create_account	user_id=14	2025-09-06 14:36:18.364581	2025-09-06 14:36:18.364581	\N
107	1	create_employee	IT004 Stephen Steve Sameniada	2025-09-06 14:37:19.192503	2025-09-06 14:37:19.192503	\N
108	1	create_account	user_id=15	2025-09-06 14:37:31.640486	2025-09-06 14:37:31.640486	\N
109	1	login	{"event":"login","ip":"136.158.43.151","ua":"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36"}	2025-09-06 19:23:59.518161	2025-09-06 19:23:59.518161	\N
110	8	login	{"event":"login","ip":"175.176.27.175","ua":"Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Mobile Safari/537.36"}	2025-09-06 22:03:20.728534	2025-09-06 22:03:20.728534	\N
111	8	export_csv	leave_requests	2025-09-06 22:03:58.646668	2025-09-06 22:03:58.646668	\N
112	\N	login.failed	Failed login for admin@hrms.local	2025-09-06 22:07:45.897752	2025-09-06 22:07:45.897752	\N
113	\N	login.failed	Failed login for velasco.raeneilbscs2023@gmail.com	2025-09-06 22:07:57.618653	2025-09-06 22:07:57.618653	\N
114	\N	login.failed	Failed login for velasco.raeneilbscs2023@gmail.com	2025-09-06 22:08:24.675255	2025-09-06 22:08:24.675255	\N
115	\N	login.failed	Failed login for velasco.raeneilbscs2023@gmail.com	2025-09-06 22:08:43.031772	2025-09-06 22:08:43.031772	\N
116	8	logout	User logged out	2025-09-06 22:11:26.424527	2025-09-06 22:11:26.424527	\N
117	1	login	{"event":"login","ip":"136.158.43.239","ua":"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36"}	2025-09-07 07:42:41.409055	2025-09-07 07:42:41.409055	\N
118	1	login	{"event":"login","ip":"136.158.43.239","ua":"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36"}	2025-09-07 08:46:46.835056	2025-09-07 08:46:46.835056	\N
119	15	login	{"event":"login","ip":"131.226.101.19","ua":"Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36"}	2025-09-08 03:45:01.238842	2025-09-08 03:45:01.238842	\N
120	\N	login.failed	Failed login for alop.gregjohnpaulbscs2023@gmail.com	2025-09-09 02:50:52.038574	2025-09-09 02:50:52.038574	\N
121	\N	login.failed	Failed login for alop.gregjohnpaulbscs2023@gmail.com	2025-09-09 02:50:59.602985	2025-09-09 02:50:59.602985	\N
122	\N	login.failed	Failed login for alop.gregjohnpaulbscs2023@gmail.com	2025-09-09 02:51:00.84302	2025-09-09 02:51:00.84302	\N
123	\N	login.failed	Failed login for alop.gregjohnpaulbscs2023@gmail.com	2025-09-09 02:51:02.314045	2025-09-09 02:51:02.314045	\N
124	\N	login.failed	Failed login for alop.gregjohnpaulbscs2023@gmail.com	2025-09-09 02:51:02.570798	2025-09-09 02:51:02.570798	\N
125	\N	login.failed	Failed login for alop.gregjohnpaulbscs2023@gmail.com	2025-09-09 02:52:29.961248	2025-09-09 02:52:29.961248	\N
126	\N	login.failed	Failed login for velasco.raeneilbscs2023@gmail.com	2025-09-09 14:30:20.099552	2025-09-09 14:30:20.099552	\N
127	8	login	{"event":"login","ip":"175.176.27.229","ua":"Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Mobile Safari/537.36"}	2025-09-09 14:30:32.868708	2025-09-09 14:30:32.868708	\N
128	8	account.password	Changed password	2025-09-09 14:33:52.744773	2025-09-09 14:33:52.744773	\N
129	8	account.password	Changed password	2025-09-09 14:34:10.587134	2025-09-09 14:34:10.587134	\N
130	7	login	{"event":"login","ip":"112.203.41.113","ua":"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36"}	2025-09-10 05:41:52.820514	2025-09-10 05:41:52.820514	\N
131	7	leave_filed	{"employee_id":3,"leave_type":"sick","start_date":"2025-09-12","end_date":"2025-09-23","days":"12.00"}	2025-09-10 06:24:47.587139	2025-09-10 06:24:47.587139	\N
132	7	login	{"event":"login","ip":"112.203.41.113","ua":"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36"}	2025-09-10 09:20:15.908386	2025-09-10 09:20:15.908386	\N
133	\N	login.failed	Failed login for velasco.raeneilbscs2023@gmail.com	2025-09-10 13:21:39.852827	2025-09-10 13:21:39.852827	\N
134	\N	login.failed	Failed login for velasco.raeneilbscs2023@gmail.com	2025-09-10 13:21:50.499061	2025-09-10 13:21:50.499061	\N
135	\N	login.failed	Failed login for velasco.raeneilbscs2023@gmail.com	2025-09-10 13:22:01.231642	2025-09-10 13:22:01.231642	\N
136	8	login	{"event":"login","ip":"175.176.24.79","ua":"Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Mobile Safari/537.36"}	2025-09-10 13:22:23.199801	2025-09-10 13:22:23.199801	\N
137	\N	login.failed	Failed login for velasco.raeneilbscs2023@gmail.com	2025-09-10 14:11:19.767318	2025-09-10 14:11:19.767318	\N
138	8	login	{"event":"login","ip":"175.176.24.79","ua":"Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Mobile Safari/537.36"}	2025-09-10 14:11:27.846622	2025-09-10 14:11:27.846622	\N
139	8	logout	User logged out	2025-09-10 14:19:16.440074	2025-09-10 14:19:16.440074	\N
140	8	login	{"event":"login","ip":"175.176.24.79","ua":"Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Mobile Safari/537.36"}	2025-09-11 02:42:48.18774	2025-09-11 02:42:48.18774	\N
141	8	logout	User logged out	2025-09-11 02:49:46.509277	2025-09-11 02:49:46.509277	\N
142	1	login	{"event":"login","ip":"136.158.43.239","ua":"Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36"}	2025-09-11 13:54:12.385609	2025-09-11 13:54:12.385609	\N
143	1	apply_role_defaults	user_id=7	2025-09-11 13:54:27.861016	2025-09-11 13:54:27.861016	\N
144	1	apply_role_defaults	{"module":"account","status":"success","meta":{"user_id":7}}	2025-09-11 13:54:27.863034	2025-09-11 13:54:27.863034	\N
145	1	update_access_permissions	user_id=7	2025-09-11 13:54:42.823331	2025-09-11 13:54:42.823331	\N
146	1	update_access_permissions	{"module":"account","status":"success","meta":{"user_id":7}}	2025-09-11 13:54:42.825685	2025-09-11 13:54:42.825685	\N
147	8	login	{"event":"login","ip":"175.176.24.102","ua":"Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Mobile Safari/537.36"}	2025-09-11 23:14:48.933432	2025-09-11 23:14:48.933432	\N
148	8	attendance.create	Attendance for emp #5 on 2025-09-12 created	2025-09-11 23:18:23.753533	2025-09-11 23:18:23.753533	\N
149	8	attendance.create	Attendance for emp #11 on 2025-09-12 created	2025-09-11 23:18:56.523333	2025-09-11 23:18:56.523333	\N
150	\N	login.failed	Failed login for admin@hrms.local	2025-09-12 09:43:04.136551	2025-09-12 09:43:04.136551	\N
151	1	login	{"event":"login","ip":"110.54.148.3","ua":"Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36"}	2025-09-12 09:43:20.065923	2025-09-12 09:43:20.065923	\N
152	1	update_access_permissions	user_id=15	2025-09-12 09:43:49.700902	2025-09-12 09:43:49.700902	\N
153	1	update_access_permissions	{"module":"account","status":"success","meta":{"user_id":15}}	2025-09-12 09:43:49.703553	2025-09-12 09:43:49.703553	\N
154	1	logout	User logged out	2025-09-12 09:44:01.995484	2025-09-12 09:44:01.995484	\N
155	15	login	{"event":"login","ip":"110.54.148.3","ua":"Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36"}	2025-09-12 09:44:08.665132	2025-09-12 09:44:08.665132	\N
156	\N	login.failed	Failed login for codera.ivannbsis2023@gmail.com	2025-09-13 02:31:37.487884	2025-09-13 02:31:37.487884	\N
157	8	login	{"event":"login","ip":"175.176.24.206","ua":"Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Mobile Safari/537.36"}	2025-09-13 05:05:17.753301	2025-09-13 05:05:17.753301	\N
158	8	logout	User logged out	2025-09-13 05:13:13.203854	2025-09-13 05:13:13.203854	\N
159	\N	login.failed	Failed login for bobis.daniel.bscs2023@gmail.com	2025-09-13 05:13:36.457143	2025-09-13 05:13:36.457143	\N
160	8	login	{"event":"login","ip":"175.176.24.206","ua":"Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Mobile Safari/537.36"}	2025-09-13 05:13:51.222534	2025-09-13 05:13:51.222534	\N
161	8	logout	User logged out	2025-09-13 05:14:50.315079	2025-09-13 05:14:50.315079	\N
162	6	login	{"event":"login","ip":"175.176.24.206","ua":"Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Mobile Safari/537.36"}	2025-09-13 05:15:11.615787	2025-09-13 05:15:11.615787	\N
163	15	login	{"event":"login","ip":"131.226.103.70","ua":"Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Mobile Safari/537.36"}	2025-09-17 13:49:02.719362	2025-09-17 13:49:02.719362	\N
164	\N	login.failed	Failed login for admin@hrms.local	2025-09-18 00:00:06.972022	2025-09-18 00:00:06.972022	\N
165	1	login	{"event":"login","ip":"136.158.42.135","ua":"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36"}	2025-09-18 00:00:15.707528	2025-09-18 00:00:15.707528	\N
166	1	logout	User logged out	2025-09-18 00:09:26.982842	2025-09-18 00:09:26.982842	\N
167	\N	login.failed	Failed login for pereyra.geroearlbscs2023@gmail.com	2025-09-18 00:09:38.756139	2025-09-18 00:09:38.756139	\N
168	\N	login.failed	Failed login for pereyra.geroearlbscs2023@gmail.com	2025-09-18 00:10:14.70037	2025-09-18 00:10:14.70037	\N
169	\N	login.failed	Failed login for pereyra.geroearlbscs2023@gmail.com	2025-09-18 00:10:24.09503	2025-09-18 00:10:24.09503	\N
170	6	login	{"event":"login","ip":"136.158.42.135","ua":"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36"}	2025-09-18 00:10:45.704032	2025-09-18 00:10:45.704032	\N
171	6	leave_filed	{"employee_id":2,"leave_type":"vacation","start_date":"2025-12-25","end_date":"2026-01-04","days":"11.00"}	2025-09-18 00:11:39.335582	2025-09-18 00:11:39.335582	\N
172	6	logout	User logged out	2025-09-18 00:12:37.058483	2025-09-18 00:12:37.058483	\N
173	1	login	{"event":"login","ip":"136.158.42.135","ua":"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36"}	2025-09-18 00:12:46.632256	2025-09-18 00:12:46.632256	\N
174	1	update_access_permissions	user_id=6	2025-09-18 00:13:42.012094	2025-09-18 00:13:42.012094	\N
175	1	update_access_permissions	{"module":"account","status":"success","meta":{"user_id":6}}	2025-09-18 00:13:42.016028	2025-09-18 00:13:42.016028	\N
176	1	logout	User logged out	2025-09-18 00:13:46.496536	2025-09-18 00:13:46.496536	\N
177	6	login	{"event":"login","ip":"136.158.42.135","ua":"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36"}	2025-09-18 00:13:55.002896	2025-09-18 00:13:55.002896	\N
178	6	logout	User logged out	2025-09-18 00:14:55.733828	2025-09-18 00:14:55.733828	\N
179	1	login	{"event":"login","ip":"136.158.42.135","ua":"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36"}	2025-09-18 00:15:05.529433	2025-09-18 00:15:05.529433	\N
180	1	attendance.create	Attendance for emp #2 on 2025-09-18 created	2025-09-18 00:16:12.238242	2025-09-18 00:16:12.238242	\N
181	1	update_access_permissions	user_id=6	2025-09-18 00:19:55.137239	2025-09-18 00:19:55.137239	\N
182	1	update_access_permissions	{"module":"account","status":"success","meta":{"user_id":6}}	2025-09-18 00:19:55.140499	2025-09-18 00:19:55.140499	\N
183	6	login	{"event":"login","ip":"136.158.42.135","ua":"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36"}	2025-09-18 00:20:13.751951	2025-09-18 00:20:13.751951	\N
184	6	recruitment_create	id=1	2025-09-18 00:31:53.262753	2025-09-18 00:31:53.262753	\N
\.


--
-- Data for Name: departments; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.departments (id, name, description, created_at, updated_at) FROM stdin;
1	IT Department		2025-09-05 15:53:29.666715	2025-09-05 15:53:29.666715
2	HR Department		2025-09-06 06:22:46.957143	2025-09-06 06:22:46.957143
4	Operation Management		2025-09-06 06:24:42.192104	2025-09-06 06:24:42.192104
3	Engineering Department		2025-09-06 06:23:22.97946	2025-09-06 06:28:00.617926
5	Accounting Department		2025-09-06 06:48:22.143479	2025-09-06 06:48:22.143479
\.


--
-- Data for Name: departments_backup; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.departments_backup (id, name, description, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: document_assignments; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.document_assignments (id, document_id, employee_id, department_id, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: documents; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.documents (id, title, doc_type, file_path, created_by, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: employees; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.employees (id, user_id, employee_code, first_name, last_name, email, phone, address, department_id, position_id, hire_date, employment_type, status, salary, created_at, updated_at) FROM stdin;
1	5	IT001	Daniel	Bobis	bobis.daniel.bscs2023@gmail.com	9000000000	caloocan city	1	1	2025-09-05	regular	active	50000.00	2025-09-05 15:57:42.961665	2025-09-06 06:40:45.960789
2	6	IT002	Gero Earl	Pereyra	pereyra.geroearlbscs2023@gmail.com	09123456789	Caloocan City	1	5	2025-09-06	regular	active	100000.00	2025-09-06 08:22:37.087139	2025-09-06 08:25:07.798349
3	7	IT003	Greg John Paul	Alop	alop.gregjohnpaulbscs2023@gmail.com	09123456789	Caloocan	1	5	2025-09-06	regular	active	100000.00	2025-09-06 08:36:01.403504	2025-09-06 08:38:26.015537
4	8	HR001	Raeneil	Velasco	velasco.raeneilbscs2023@gmail.com	09123456789	Caloocan City	2	4	2025-09-06	regular	active	100000.00	2025-09-06 14:27:01.057678	2025-09-06 14:27:21.564108
5	9	HR002	Mark Andrei	Merto	merto.markbscs2023@gmail.com	09123456789	Caloocan	2	4	2025-09-06	regular	active	100000.00	2025-09-06 14:28:03.519603	2025-09-06 14:28:13.374294
6	10	HR003	Karylle	De Torres	detorres.karyllebscs2023@gmail.com	09123456789	Caloocan City	2	3	2025-09-06	regular	active	0.00	2025-09-06 14:29:12.061286	2025-09-06 14:29:28.515009
7	11	HR004	Jhon Carlos	Bagaforo	bagaforo.jhoncarlosbscs2023@gmail.com	091234546789	Caloocan City	2	3	2025-09-06	regular	active	0.00	2025-09-06 14:31:19.468284	2025-09-06 14:32:19.779705
8	12	HR005	Stephanie	Cueto	cueto.stephaniebscs2023@gmail.com	09123456789	Caloocan City	2	2	2025-09-06	regular	active	100000.00	2025-09-06 14:33:55.382594	2025-09-06 14:34:10.057212
9	13	HR006	Mark Joseph	Buban	Buban.markjosephbscs2023@gmail.com	09123456789	Caloocan City	2	4	2025-09-06	regular	active	0.00	2025-09-06 14:34:56.912585	2025-09-06 14:35:08.83682
10	14	HR007	Mark Clent	Bigayan	bigayan.markclent.bscs2023@gmail.com	09123456789	Caloocan City	2	3	2025-09-06	regular	active	0.00	2025-09-06 14:36:07.154623	2025-09-06 14:36:18.128757
11	15	IT004	Stephen Steve	Sameniada	sameniadastephenstevebscs2023@gmail.com	09123456879	Caloocan City	1	5	2025-09-06	regular	active	0.00	2025-09-06 14:37:19.190523	2025-09-06 14:37:31.406841
\.


--
-- Data for Name: employees_backup; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.employees_backup (id, user_id, employee_code, first_name, last_name, email, phone, address, department_id, position_id, hire_date, employment_type, status, salary, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: leave_request_actions; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.leave_request_actions (id, leave_request_id, action, reason, acted_by, acted_at) FROM stdin;
\.


--
-- Data for Name: leave_requests; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.leave_requests (id, employee_id, leave_type, start_date, end_date, total_days, status, remarks, created_at, updated_at) FROM stdin;
1	3	sick	2025-09-12	2025-09-23	12.00	pending	yggh	2025-09-10 06:24:47.585115	2025-09-10 06:24:47.585115
2	2	vacation	2025-12-25	2026-01-04	11.00	pending		2025-09-18 00:11:39.333046	2025-09-18 00:11:39.333046
\.


--
-- Data for Name: leave_requests_backup; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.leave_requests_backup (id, employee_id, leave_type, start_date, end_date, total_days, status, remarks, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: notification_reads; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.notification_reads (notification_id, user_id, read_at) FROM stdin;
\.


--
-- Data for Name: notifications; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.notifications (id, user_id, message, is_read, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: payroll; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.payroll (id, employee_id, period_id, basic_pay, allowances, deductions, net_pay, released_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: payroll_backup; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.payroll_backup (id, employee_id, period_id, basic_pay, allowances, deductions, net_pay, released_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: payroll_periods; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.payroll_periods (id, period_start, period_end, status, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: pdf_templates; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.pdf_templates (id, report_key, settings, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: performance_reviews; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.performance_reviews (id, employee_id, review_date, kpi_score, remarks, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: positions; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.positions (id, department_id, name, description, base_salary, created_at, updated_at) FROM stdin;
1	1	System Administrator		20000.00	2025-09-05 15:58:00.4422	2025-09-05 15:58:00.4422
2	2	HR Supervisor		30000.00	2025-09-06 06:25:15.440834	2025-09-06 06:25:15.440834
3	2	HR Payroll		25000.00	2025-09-06 06:25:33.095783	2025-09-06 06:25:33.095783
5	1	System Administrator Assistant		30000.00	2025-09-06 06:27:01.493919	2025-09-06 06:27:01.493919
4	2	HR Hiring		20000.00	2025-09-06 06:25:49.131549	2025-09-06 06:27:12.250805
\.


--
-- Data for Name: positions_backup; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.positions_backup (id, department_id, name, description, base_salary, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: recruitment; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.recruitment (id, full_name, email, phone, position_applied, template_id, converted_employee_id, resume_path, status, notes, created_at, updated_at) FROM stdin;
1	CJ Villarba	cjvillarba1980@gmail.com		HR	\N	\N	\N	new	\N	2025-09-18 00:31:53.259717	2025-09-18 00:31:53.259717
\.


--
-- Data for Name: recruitment_files; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.recruitment_files (id, recruitment_id, label, file_path, uploaded_by, created_at) FROM stdin;
\.


--
-- Data for Name: recruitment_template_fields; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.recruitment_template_fields (template_id, field_name, is_required) FROM stdin;
\.


--
-- Data for Name: recruitment_template_files; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.recruitment_template_files (template_id, label, is_required) FROM stdin;
\.


--
-- Data for Name: recruitment_templates; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.recruitment_templates (id, name, description, created_at) FROM stdin;
\.


--
-- Data for Name: roles_meta; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.roles_meta (role_name, label, description, is_active, created_at, updated_at) FROM stdin;
admin_assistant	Admin Assistant	\N	1	2025-09-06 06:46:12.469227	2025-09-06 06:46:12.469227
employee	Employee	\N	1	2025-09-06 06:46:12.469227	2025-09-06 06:46:12.469227
hr_recruit	Hr Recruit	\N	1	2025-09-06 06:46:12.469227	2025-09-06 06:46:12.469227
hr_supervisor	Hr Supervisor	\N	1	2025-09-06 06:46:12.469227	2025-09-06 06:46:12.469227
hr	Hr	\N	1	2025-09-06 06:46:12.469227	2025-09-06 06:46:12.469227
hr_payroll	Hr Payroll	\N	1	2025-09-06 06:46:12.469227	2025-09-06 06:46:12.469227
accountant	Accountant	\N	1	2025-09-06 06:46:12.469227	2025-09-06 06:46:12.469227
admin	Admin	\N	1	2025-09-06 06:46:12.469227	2025-09-06 06:46:12.469227
manager	Manager	\N	1	2025-09-06 06:46:12.469227	2025-09-06 06:46:12.469227
\.


--
-- Data for Name: roles_meta_permissions; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.roles_meta_permissions (role_name, module, level) FROM stdin;
\.


--
-- Data for Name: schema_migrations; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.schema_migrations (filename, checksum, applied_at) FROM stdin;
\.


--
-- Data for Name: system_logs; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.system_logs (id, code, message, module, file, line, func, context, created_at) FROM stdin;
1	DB2901	Audit insert failed: SQLSTATE[23503]: Foreign key violation: 7 ERROR:  insert or update on table "audit_logs" violates foreign key constraint "fk_audit_user"\nDETAIL:  Key (user_id)=(5) is not present in table "users".	auth	/app/includes/auth.php	62		{"action":"logout"}	2025-09-05 15:38:41.40338
2	DB2801	Employee profile PDF query failed - Unsupported operand types: string - string	employees	/app/modules/employees/pdf_profile.php	14		{"id":2}	2025-09-06 08:24:06.377919
3	EXPORT-CSV	Exported leave requests CSV	leave	/app/modules/leave/csv.php	20		{"status":"","adminView":false,"count":0}	2025-09-06 22:03:58.644735
4	DB2412	Execute failed: attendance insert - SQLSTATE[22007]: Invalid datetime format: 7 ERROR:  invalid input syntax for type time: ""\nCONTEXT:  unnamed portal parameter $4 = ''	attendance	/app/modules/attendance/create.php	39		\N	2025-09-11 23:17:58.680535
5	DB2412	Execute failed: attendance insert - SQLSTATE[22007]: Invalid datetime format: 7 ERROR:  invalid input syntax for type time: ""\nCONTEXT:  unnamed portal parameter $4 = ''	attendance	/app/modules/attendance/create.php	39		\N	2025-09-18 00:15:30.635868
6	DB2412	Execute failed: attendance insert - SQLSTATE[22007]: Invalid datetime format: 7 ERROR:  invalid input syntax for type time: ""\nCONTEXT:  unnamed portal parameter $4 = ''	attendance	/app/modules/attendance/create.php	39		\N	2025-09-18 00:15:57.876918
\.


--
-- Data for Name: user_access_permissions; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.user_access_permissions (user_id, module, level, created_at) FROM stdin;
7	recruitment	admin	2025-09-06 08:38:26.015537
7	documents	admin	2025-09-06 08:38:26.015537
7	reports	admin	2025-09-06 08:38:26.015537
7	settings	admin	2025-09-06 08:38:26.015537
15	hr	write	2025-09-06 14:37:31.406841
15	employees	write	2025-09-06 14:37:31.406841
15	attendance	write	2025-09-06 14:37:31.406841
15	payroll	write	2025-09-06 14:37:31.406841
6	payroll	write	2025-09-06 08:25:07.798349
6	recruitment	write	2025-09-06 08:25:07.798349
6	documents	write	2025-09-06 08:25:07.798349
6	reports	write	2025-09-06 08:25:07.798349
6	settings	write	2025-09-06 08:25:07.798349
5	attendance	admin	2025-09-06 06:40:45.960789
5	documents	admin	2025-09-06 06:40:45.960789
5	employees	admin	2025-09-06 06:40:45.960789
5	hr	admin	2025-09-06 06:40:45.960789
5	payroll	admin	2025-09-06 06:40:45.960789
5	recruitment	admin	2025-09-06 06:40:45.960789
5	reports	admin	2025-09-06 06:40:45.960789
5	settings	admin	2025-09-06 06:40:45.960789
1	hr	admin	2025-09-06 07:08:19.140077
1	employees	admin	2025-09-06 07:08:19.140077
1	attendance	admin	2025-09-06 07:08:19.140077
1	payroll	admin	2025-09-06 07:08:19.140077
1	recruitment	admin	2025-09-06 07:08:19.140077
1	documents	admin	2025-09-06 07:08:19.140077
1	reports	admin	2025-09-06 07:08:19.140077
1	settings	admin	2025-09-06 07:08:19.140077
8	hr	none	2025-09-06 14:27:21.564108
8	employees	none	2025-09-06 14:27:21.564108
8	attendance	none	2025-09-06 14:27:21.564108
8	payroll	none	2025-09-06 14:27:21.564108
8	recruitment	none	2025-09-06 14:27:21.564108
8	documents	none	2025-09-06 14:27:21.564108
8	reports	none	2025-09-06 14:27:21.564108
8	settings	none	2025-09-06 14:27:21.564108
9	hr	none	2025-09-06 14:28:13.374294
9	employees	none	2025-09-06 14:28:13.374294
9	attendance	none	2025-09-06 14:28:13.374294
9	payroll	none	2025-09-06 14:28:13.374294
9	recruitment	none	2025-09-06 14:28:13.374294
9	documents	none	2025-09-06 14:28:13.374294
9	reports	none	2025-09-06 14:28:13.374294
9	settings	none	2025-09-06 14:28:13.374294
10	hr	none	2025-09-06 14:29:28.515009
10	employees	none	2025-09-06 14:29:28.515009
10	attendance	none	2025-09-06 14:29:28.515009
10	payroll	none	2025-09-06 14:29:28.515009
10	recruitment	none	2025-09-06 14:29:28.515009
10	documents	none	2025-09-06 14:29:28.515009
10	reports	none	2025-09-06 14:29:28.515009
10	settings	none	2025-09-06 14:29:28.515009
11	hr	none	2025-09-06 14:32:19.779705
11	employees	none	2025-09-06 14:32:19.779705
11	attendance	none	2025-09-06 14:32:19.779705
11	payroll	none	2025-09-06 14:32:19.779705
11	recruitment	none	2025-09-06 14:32:19.779705
11	documents	none	2025-09-06 14:32:19.779705
11	reports	none	2025-09-06 14:32:19.779705
11	settings	none	2025-09-06 14:32:19.779705
12	hr	none	2025-09-06 14:34:10.057212
12	employees	none	2025-09-06 14:34:10.057212
12	attendance	none	2025-09-06 14:34:10.057212
12	payroll	none	2025-09-06 14:34:10.057212
12	recruitment	none	2025-09-06 14:34:10.057212
12	documents	none	2025-09-06 14:34:10.057212
12	reports	none	2025-09-06 14:34:10.057212
12	settings	none	2025-09-06 14:34:10.057212
13	hr	none	2025-09-06 14:35:08.83682
13	employees	none	2025-09-06 14:35:08.83682
13	attendance	none	2025-09-06 14:35:08.83682
13	payroll	none	2025-09-06 14:35:08.83682
13	recruitment	none	2025-09-06 14:35:08.83682
13	documents	none	2025-09-06 14:35:08.83682
13	reports	none	2025-09-06 14:35:08.83682
13	settings	none	2025-09-06 14:35:08.83682
14	hr	none	2025-09-06 14:36:18.128757
14	employees	none	2025-09-06 14:36:18.128757
14	attendance	none	2025-09-06 14:36:18.128757
14	payroll	none	2025-09-06 14:36:18.128757
14	recruitment	none	2025-09-06 14:36:18.128757
14	documents	none	2025-09-06 14:36:18.128757
14	reports	none	2025-09-06 14:36:18.128757
14	settings	none	2025-09-06 14:36:18.128757
7	hr	admin	2025-09-06 08:38:26.015537
7	employees	admin	2025-09-06 08:38:26.015537
7	attendance	admin	2025-09-06 08:38:26.015537
7	payroll	admin	2025-09-06 08:38:26.015537
15	recruitment	write	2025-09-06 14:37:31.406841
15	documents	write	2025-09-06 14:37:31.406841
15	reports	write	2025-09-06 14:37:31.406841
15	settings	write	2025-09-06 14:37:31.406841
6	hr	write	2025-09-06 08:25:07.798349
6	employees	write	2025-09-06 08:25:07.798349
6	attendance	admin	2025-09-06 08:25:07.798349
\.


--
-- Data for Name: users; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.users (id, email, password_hash, full_name, role, status, last_login, created_at, updated_at) FROM stdin;
7	alop.gregjohnpaulbscs2023@gmail.com	$2y$12$L/LCYAYcBEfqhrSA4sNzs.rCbjoTgB44tYt1bOk00vkOTw5I.CWnm	Greg John Paul Alop	admin_assistant	active	2025-09-10 09:20:15.905553	2025-09-06 08:38:26.015537	2025-09-10 09:20:15.905553
8	velasco.raeneilbscs2023@gmail.com	$2y$12$wLrJcTp0S.DSIIHVI3jAS.UtBD6GrmO2WtGRA0esPs28tEf.Tleva	Raeneil Velasco	hr	active	2025-09-13 05:13:51.219766	2025-09-06 14:27:21.564108	2025-09-13 05:13:51.219766
5	bobis.daniel.bscs2023@gmail.com	$2y$12$goX/QKAN31cMPnCBEuXxk.q17inB.RgdQL2iJDZQRXnhxnMj5uX5K	Daniel Bobis	admin	active	2025-09-06 06:48:01.566454	2025-09-06 06:40:45.960789	2025-09-06 06:48:01.566454
15	sameniadastephenstevebscs2023@gmail.com	$2y$12$ED/oO5FVZTBkFVSAe6ZAnuzKdcYZjE8jKGKsgej4xnVm8mhu4bsVW	Stephen Steve Sameniada	admin_assistant	active	2025-09-17 13:49:02.713378	2025-09-06 14:37:31.406841	2025-09-17 13:49:02.713378
10	detorres.karyllebscs2023@gmail.com	$2y$12$tNOlVoGw2q3xwAB1zYbfu.LEdl8M.3xsoiPJjtZ9VPSwKLCEq.2uy	Karylle De Torres	hr_payroll	active	\N	2025-09-06 14:29:28.515009	2025-09-06 14:29:28.515009
1	admin@hrms.local	$2y$12$M01fAq2rkNgfe8N/x9b1MuhVFGlH7IxT0/hGt0q.YBSRtkY1bzvGC	System Admin	admin	active	2025-09-18 00:15:05.526165	2025-09-05 15:37:39.621504	2025-09-18 00:15:05.526165
9	merto.markbscs2023@gmail.com	$2y$12$cF29lUyIQBU7o0NzPv34RekFfj5vMshF/pJPz3Lz.B1O0WoNhJdQq	Mark Andrei Merto	hr_recruit	active	\N	2025-09-06 14:28:13.374294	2025-09-06 14:30:25.871409
11	bagaforo.jhoncarlosbscs2023@gmail.com	$2y$12$oxY.ILDgQOIsVPaV/BwLVOMLOeEDdKId1eGLXXvHiqIkvch6gT2aK	Jhon Carlos Bagaforo	hr_payroll	active	\N	2025-09-06 14:32:19.779705	2025-09-06 14:32:30.82528
12	cueto.stephaniebscs2023@gmail.com	$2y$12$QOP/MPndv/DIXqQgF9E7lOHu.P/4JecdZDcZfAZuJXhiI1R9Fzih.	Stephanie Cueto	hr_supervisor	active	\N	2025-09-06 14:34:10.057212	2025-09-06 14:34:10.057212
13	Buban.markjosephbscs2023@gmail.com	$2y$12$m9HiZXtW4hXLFtN3Gpym.Oy.qpp4qgA/fB9yG4cvUMv/iaMI7CMW6	Mark Joseph Buban	hr_recruit	active	\N	2025-09-06 14:35:08.83682	2025-09-06 14:35:15.697915
14	bigayan.markclent.bscs2023@gmail.com	$2y$12$VZGyNeefCYS/a/9Y1vgc3eVEpQfLf.N6jc20VnT2EzOjQQhDNuY1a	Mark Clent Bigayan	hr_payroll	active	\N	2025-09-06 14:36:18.128757	2025-09-06 14:36:18.128757
6	pereyra.geroearlbscs2023@gmail.com	$2y$12$gMfBWfG.krN1vE4Icb9iz.PhMMOZsJddb43x8rSGcvUTZgh9ZHJiG	Gero Earl Pereyra	admin_assistant	active	2025-09-18 00:20:13.749071	2025-09-06 08:25:07.798349	2025-09-18 00:20:13.749071
\.


--
-- Data for Name: users_backup; Type: TABLE DATA; Schema: public; Owner: uen9p9diua190r
--

COPY public.users_backup (id, email, password_hash, full_name, role, status, last_login, created_at, updated_at) FROM stdin;
3	try@gmail.com	$2y$12$N4Gyle7kP6Mwx73ioiiim.LzFiqSgXMhfNOanFLbcqSZyv0DpxICe	Try	employee	active	\N	2025-09-05 16:21:34.64546	2025-09-05 16:21:34.64546
4	test@gmail.com	$2y$12$tBQSne1/592tfOLL1vggFO05QEt8vQVpp8wPU0ZW6TbT4.y0trxse	try	employee	active	\N	2025-09-05 16:27:08.528742	2025-09-05 16:27:08.528742
\.


--
-- Name: access_templates_id_seq; Type: SEQUENCE SET; Schema: public; Owner: uen9p9diua190r
--

SELECT pg_catalog.setval('public.access_templates_id_seq', 7, true);


--
-- Name: action_reversals_id_seq; Type: SEQUENCE SET; Schema: public; Owner: uen9p9diua190r
--

SELECT pg_catalog.setval('public.action_reversals_id_seq', 1, false);


--
-- Name: attendance_id_seq; Type: SEQUENCE SET; Schema: public; Owner: uen9p9diua190r
--

SELECT pg_catalog.setval('public.attendance_id_seq', 3, true);


--
-- Name: audit_logs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: uen9p9diua190r
--

SELECT pg_catalog.setval('public.audit_logs_id_seq', 184, true);


--
-- Name: departments_backup_id_seq; Type: SEQUENCE SET; Schema: public; Owner: uen9p9diua190r
--

SELECT pg_catalog.setval('public.departments_backup_id_seq', 1, false);


--
-- Name: departments_id_seq; Type: SEQUENCE SET; Schema: public; Owner: uen9p9diua190r
--

SELECT pg_catalog.setval('public.departments_id_seq', 5, true);


--
-- Name: document_assignments_id_seq; Type: SEQUENCE SET; Schema: public; Owner: uen9p9diua190r
--

SELECT pg_catalog.setval('public.document_assignments_id_seq', 1, false);


--
-- Name: documents_id_seq; Type: SEQUENCE SET; Schema: public; Owner: uen9p9diua190r
--

SELECT pg_catalog.setval('public.documents_id_seq', 1, false);


--
-- Name: employees_backup_id_seq; Type: SEQUENCE SET; Schema: public; Owner: uen9p9diua190r
--

SELECT pg_catalog.setval('public.employees_backup_id_seq', 1, false);


--
-- Name: employees_id_seq; Type: SEQUENCE SET; Schema: public; Owner: uen9p9diua190r
--

SELECT pg_catalog.setval('public.employees_id_seq', 11, true);


--
-- Name: leave_request_actions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: uen9p9diua190r
--

SELECT pg_catalog.setval('public.leave_request_actions_id_seq', 1, false);


--
-- Name: leave_requests_backup_id_seq; Type: SEQUENCE SET; Schema: public; Owner: uen9p9diua190r
--

SELECT pg_catalog.setval('public.leave_requests_backup_id_seq', 1, false);


--
-- Name: leave_requests_id_seq; Type: SEQUENCE SET; Schema: public; Owner: uen9p9diua190r
--

SELECT pg_catalog.setval('public.leave_requests_id_seq', 2, true);


--
-- Name: notifications_id_seq; Type: SEQUENCE SET; Schema: public; Owner: uen9p9diua190r
--

SELECT pg_catalog.setval('public.notifications_id_seq', 1, false);


--
-- Name: payroll_backup_id_seq; Type: SEQUENCE SET; Schema: public; Owner: uen9p9diua190r
--

SELECT pg_catalog.setval('public.payroll_backup_id_seq', 1, false);


--
-- Name: payroll_id_seq; Type: SEQUENCE SET; Schema: public; Owner: uen9p9diua190r
--

SELECT pg_catalog.setval('public.payroll_id_seq', 1, false);


--
-- Name: payroll_periods_id_seq; Type: SEQUENCE SET; Schema: public; Owner: uen9p9diua190r
--

SELECT pg_catalog.setval('public.payroll_periods_id_seq', 1, false);


--
-- Name: pdf_templates_id_seq; Type: SEQUENCE SET; Schema: public; Owner: uen9p9diua190r
--

SELECT pg_catalog.setval('public.pdf_templates_id_seq', 1, false);


--
-- Name: performance_reviews_id_seq; Type: SEQUENCE SET; Schema: public; Owner: uen9p9diua190r
--

SELECT pg_catalog.setval('public.performance_reviews_id_seq', 1, false);


--
-- Name: positions_backup_id_seq; Type: SEQUENCE SET; Schema: public; Owner: uen9p9diua190r
--

SELECT pg_catalog.setval('public.positions_backup_id_seq', 1, false);


--
-- Name: positions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: uen9p9diua190r
--

SELECT pg_catalog.setval('public.positions_id_seq', 5, true);


--
-- Name: recruitment_files_id_seq; Type: SEQUENCE SET; Schema: public; Owner: uen9p9diua190r
--

SELECT pg_catalog.setval('public.recruitment_files_id_seq', 1, false);


--
-- Name: recruitment_id_seq; Type: SEQUENCE SET; Schema: public; Owner: uen9p9diua190r
--

SELECT pg_catalog.setval('public.recruitment_id_seq', 1, true);


--
-- Name: recruitment_templates_id_seq; Type: SEQUENCE SET; Schema: public; Owner: uen9p9diua190r
--

SELECT pg_catalog.setval('public.recruitment_templates_id_seq', 1, false);


--
-- Name: system_logs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: uen9p9diua190r
--

SELECT pg_catalog.setval('public.system_logs_id_seq', 6, true);


--
-- Name: users_backup_id_seq; Type: SEQUENCE SET; Schema: public; Owner: uen9p9diua190r
--

SELECT pg_catalog.setval('public.users_backup_id_seq', 1, false);


--
-- Name: users_id_seq; Type: SEQUENCE SET; Schema: public; Owner: uen9p9diua190r
--

SELECT pg_catalog.setval('public.users_id_seq', 15, true);


--
-- Name: access_template_permissions access_template_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.access_template_permissions
    ADD CONSTRAINT access_template_permissions_pkey PRIMARY KEY (template_id, module);


--
-- Name: access_templates access_templates_name_key; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.access_templates
    ADD CONSTRAINT access_templates_name_key UNIQUE (name);


--
-- Name: access_templates access_templates_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.access_templates
    ADD CONSTRAINT access_templates_pkey PRIMARY KEY (id);


--
-- Name: action_reversals action_reversals_audit_log_id_key; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.action_reversals
    ADD CONSTRAINT action_reversals_audit_log_id_key UNIQUE (audit_log_id);


--
-- Name: action_reversals action_reversals_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.action_reversals
    ADD CONSTRAINT action_reversals_pkey PRIMARY KEY (id);


--
-- Name: attendance attendance_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.attendance
    ADD CONSTRAINT attendance_pkey PRIMARY KEY (id);


--
-- Name: audit_logs audit_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT audit_logs_pkey PRIMARY KEY (id);


--
-- Name: departments_backup departments_backup_name_key; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.departments_backup
    ADD CONSTRAINT departments_backup_name_key UNIQUE (name);


--
-- Name: departments_backup departments_backup_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.departments_backup
    ADD CONSTRAINT departments_backup_pkey PRIMARY KEY (id);


--
-- Name: departments departments_name_key; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.departments
    ADD CONSTRAINT departments_name_key UNIQUE (name);


--
-- Name: departments departments_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.departments
    ADD CONSTRAINT departments_pkey PRIMARY KEY (id);


--
-- Name: document_assignments document_assignments_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.document_assignments
    ADD CONSTRAINT document_assignments_pkey PRIMARY KEY (id);


--
-- Name: documents documents_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.documents
    ADD CONSTRAINT documents_pkey PRIMARY KEY (id);


--
-- Name: employees_backup employees_backup_employee_code_key; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.employees_backup
    ADD CONSTRAINT employees_backup_employee_code_key UNIQUE (employee_code);


--
-- Name: employees_backup employees_backup_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.employees_backup
    ADD CONSTRAINT employees_backup_pkey PRIMARY KEY (id);


--
-- Name: employees employees_employee_code_key; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.employees
    ADD CONSTRAINT employees_employee_code_key UNIQUE (employee_code);


--
-- Name: employees employees_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.employees
    ADD CONSTRAINT employees_pkey PRIMARY KEY (id);


--
-- Name: leave_request_actions leave_request_actions_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.leave_request_actions
    ADD CONSTRAINT leave_request_actions_pkey PRIMARY KEY (id);


--
-- Name: leave_requests_backup leave_requests_backup_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.leave_requests_backup
    ADD CONSTRAINT leave_requests_backup_pkey PRIMARY KEY (id);


--
-- Name: leave_requests leave_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.leave_requests
    ADD CONSTRAINT leave_requests_pkey PRIMARY KEY (id);


--
-- Name: notification_reads notification_reads_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.notification_reads
    ADD CONSTRAINT notification_reads_pkey PRIMARY KEY (notification_id, user_id);


--
-- Name: notifications notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_pkey PRIMARY KEY (id);


--
-- Name: payroll_backup payroll_backup_employee_id_period_id_key; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.payroll_backup
    ADD CONSTRAINT payroll_backup_employee_id_period_id_key UNIQUE (employee_id, period_id);


--
-- Name: payroll_backup payroll_backup_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.payroll_backup
    ADD CONSTRAINT payroll_backup_pkey PRIMARY KEY (id);


--
-- Name: payroll_periods payroll_periods_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.payroll_periods
    ADD CONSTRAINT payroll_periods_pkey PRIMARY KEY (id);


--
-- Name: payroll payroll_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.payroll
    ADD CONSTRAINT payroll_pkey PRIMARY KEY (id);


--
-- Name: pdf_templates pdf_templates_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.pdf_templates
    ADD CONSTRAINT pdf_templates_pkey PRIMARY KEY (id);


--
-- Name: pdf_templates pdf_templates_report_key_key; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.pdf_templates
    ADD CONSTRAINT pdf_templates_report_key_key UNIQUE (report_key);


--
-- Name: performance_reviews performance_reviews_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.performance_reviews
    ADD CONSTRAINT performance_reviews_pkey PRIMARY KEY (id);


--
-- Name: positions_backup positions_backup_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.positions_backup
    ADD CONSTRAINT positions_backup_pkey PRIMARY KEY (id);


--
-- Name: positions positions_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.positions
    ADD CONSTRAINT positions_pkey PRIMARY KEY (id);


--
-- Name: recruitment_files recruitment_files_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.recruitment_files
    ADD CONSTRAINT recruitment_files_pkey PRIMARY KEY (id);


--
-- Name: recruitment recruitment_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.recruitment
    ADD CONSTRAINT recruitment_pkey PRIMARY KEY (id);


--
-- Name: recruitment_template_fields recruitment_template_fields_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.recruitment_template_fields
    ADD CONSTRAINT recruitment_template_fields_pkey PRIMARY KEY (template_id, field_name);


--
-- Name: recruitment_template_files recruitment_template_files_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.recruitment_template_files
    ADD CONSTRAINT recruitment_template_files_pkey PRIMARY KEY (template_id, label);


--
-- Name: recruitment_templates recruitment_templates_name_key; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.recruitment_templates
    ADD CONSTRAINT recruitment_templates_name_key UNIQUE (name);


--
-- Name: recruitment_templates recruitment_templates_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.recruitment_templates
    ADD CONSTRAINT recruitment_templates_pkey PRIMARY KEY (id);


--
-- Name: roles_meta_permissions roles_meta_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.roles_meta_permissions
    ADD CONSTRAINT roles_meta_permissions_pkey PRIMARY KEY (role_name, module);


--
-- Name: roles_meta roles_meta_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.roles_meta
    ADD CONSTRAINT roles_meta_pkey PRIMARY KEY (role_name);


--
-- Name: schema_migrations schema_migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.schema_migrations
    ADD CONSTRAINT schema_migrations_pkey PRIMARY KEY (filename);


--
-- Name: system_logs system_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.system_logs
    ADD CONSTRAINT system_logs_pkey PRIMARY KEY (id);


--
-- Name: attendance uniq_attendance_employee_date; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.attendance
    ADD CONSTRAINT uniq_attendance_employee_date UNIQUE (employee_id, date);


--
-- Name: payroll uniq_employee_period; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.payroll
    ADD CONSTRAINT uniq_employee_period UNIQUE (employee_id, period_id);


--
-- Name: user_access_permissions user_access_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.user_access_permissions
    ADD CONSTRAINT user_access_permissions_pkey PRIMARY KEY (user_id, module);


--
-- Name: users_backup users_backup_email_key; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.users_backup
    ADD CONSTRAINT users_backup_email_key UNIQUE (email);


--
-- Name: users_backup users_backup_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.users_backup
    ADD CONSTRAINT users_backup_pkey PRIMARY KEY (id);


--
-- Name: users users_email_key; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_key UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: employees_backup_department_id_idx; Type: INDEX; Schema: public; Owner: uen9p9diua190r
--

CREATE INDEX employees_backup_department_id_idx ON public.employees_backup USING btree (department_id);


--
-- Name: employees_backup_position_id_idx; Type: INDEX; Schema: public; Owner: uen9p9diua190r
--

CREATE INDEX employees_backup_position_id_idx ON public.employees_backup USING btree (position_id);


--
-- Name: idx_attendance_date; Type: INDEX; Schema: public; Owner: uen9p9diua190r
--

CREATE INDEX idx_attendance_date ON public.attendance USING btree (date);


--
-- Name: idx_attendance_employee; Type: INDEX; Schema: public; Owner: uen9p9diua190r
--

CREATE INDEX idx_attendance_employee ON public.attendance USING btree (employee_id);


--
-- Name: idx_audit_user; Type: INDEX; Schema: public; Owner: uen9p9diua190r
--

CREATE INDEX idx_audit_user ON public.audit_logs USING btree (user_id);


--
-- Name: idx_employees_dept; Type: INDEX; Schema: public; Owner: uen9p9diua190r
--

CREATE INDEX idx_employees_dept ON public.employees USING btree (department_id);


--
-- Name: idx_employees_pos; Type: INDEX; Schema: public; Owner: uen9p9diua190r
--

CREATE INDEX idx_employees_pos ON public.employees USING btree (position_id);


--
-- Name: idx_leave_employee; Type: INDEX; Schema: public; Owner: uen9p9diua190r
--

CREATE INDEX idx_leave_employee ON public.leave_requests USING btree (employee_id);


--
-- Name: idx_leave_status; Type: INDEX; Schema: public; Owner: uen9p9diua190r
--

CREATE INDEX idx_leave_status ON public.leave_requests USING btree (status);


--
-- Name: idx_notification_reads_user; Type: INDEX; Schema: public; Owner: uen9p9diua190r
--

CREATE INDEX idx_notification_reads_user ON public.notification_reads USING btree (user_id);


--
-- Name: idx_notifications_user; Type: INDEX; Schema: public; Owner: uen9p9diua190r
--

CREATE INDEX idx_notifications_user ON public.notifications USING btree (user_id);


--
-- Name: idx_payroll_employee; Type: INDEX; Schema: public; Owner: uen9p9diua190r
--

CREATE INDEX idx_payroll_employee ON public.payroll USING btree (employee_id);


--
-- Name: idx_payroll_period; Type: INDEX; Schema: public; Owner: uen9p9diua190r
--

CREATE INDEX idx_payroll_period ON public.payroll USING btree (period_id);


--
-- Name: idx_perf_employee; Type: INDEX; Schema: public; Owner: uen9p9diua190r
--

CREATE INDEX idx_perf_employee ON public.performance_reviews USING btree (employee_id);


--
-- Name: idx_positions_department; Type: INDEX; Schema: public; Owner: uen9p9diua190r
--

CREATE INDEX idx_positions_department ON public.positions USING btree (department_id);


--
-- Name: idx_system_logs_code; Type: INDEX; Schema: public; Owner: uen9p9diua190r
--

CREATE INDEX idx_system_logs_code ON public.system_logs USING btree (code);


--
-- Name: idx_system_logs_created; Type: INDEX; Schema: public; Owner: uen9p9diua190r
--

CREATE INDEX idx_system_logs_created ON public.system_logs USING btree (created_at);


--
-- Name: idx_uap_user; Type: INDEX; Schema: public; Owner: uen9p9diua190r
--

CREATE INDEX idx_uap_user ON public.user_access_permissions USING btree (user_id);


--
-- Name: leave_requests_backup_employee_id_idx; Type: INDEX; Schema: public; Owner: uen9p9diua190r
--

CREATE INDEX leave_requests_backup_employee_id_idx ON public.leave_requests_backup USING btree (employee_id);


--
-- Name: leave_requests_backup_status_idx; Type: INDEX; Schema: public; Owner: uen9p9diua190r
--

CREATE INDEX leave_requests_backup_status_idx ON public.leave_requests_backup USING btree (status);


--
-- Name: payroll_backup_employee_id_idx; Type: INDEX; Schema: public; Owner: uen9p9diua190r
--

CREATE INDEX payroll_backup_employee_id_idx ON public.payroll_backup USING btree (employee_id);


--
-- Name: payroll_backup_period_id_idx; Type: INDEX; Schema: public; Owner: uen9p9diua190r
--

CREATE INDEX payroll_backup_period_id_idx ON public.payroll_backup USING btree (period_id);


--
-- Name: positions_backup_department_id_idx; Type: INDEX; Schema: public; Owner: uen9p9diua190r
--

CREATE INDEX positions_backup_department_id_idx ON public.positions_backup USING btree (department_id);


--
-- Name: roles_meta trg_roles_meta_updated; Type: TRIGGER; Schema: public; Owner: uen9p9diua190r
--

CREATE TRIGGER trg_roles_meta_updated BEFORE UPDATE ON public.roles_meta FOR EACH ROW EXECUTE FUNCTION public.fn_roles_meta_set_updated();


--
-- Name: attendance trg_set_updated_at_attendance; Type: TRIGGER; Schema: public; Owner: uen9p9diua190r
--

CREATE TRIGGER trg_set_updated_at_attendance BEFORE UPDATE ON public.attendance FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: audit_logs trg_set_updated_at_audit_logs; Type: TRIGGER; Schema: public; Owner: uen9p9diua190r
--

CREATE TRIGGER trg_set_updated_at_audit_logs BEFORE UPDATE ON public.audit_logs FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: departments trg_set_updated_at_departments; Type: TRIGGER; Schema: public; Owner: uen9p9diua190r
--

CREATE TRIGGER trg_set_updated_at_departments BEFORE UPDATE ON public.departments FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: document_assignments trg_set_updated_at_document_assignments; Type: TRIGGER; Schema: public; Owner: uen9p9diua190r
--

CREATE TRIGGER trg_set_updated_at_document_assignments BEFORE UPDATE ON public.document_assignments FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: documents trg_set_updated_at_documents; Type: TRIGGER; Schema: public; Owner: uen9p9diua190r
--

CREATE TRIGGER trg_set_updated_at_documents BEFORE UPDATE ON public.documents FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: employees trg_set_updated_at_employees; Type: TRIGGER; Schema: public; Owner: uen9p9diua190r
--

CREATE TRIGGER trg_set_updated_at_employees BEFORE UPDATE ON public.employees FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: leave_requests trg_set_updated_at_leave_requests; Type: TRIGGER; Schema: public; Owner: uen9p9diua190r
--

CREATE TRIGGER trg_set_updated_at_leave_requests BEFORE UPDATE ON public.leave_requests FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: notifications trg_set_updated_at_notifications; Type: TRIGGER; Schema: public; Owner: uen9p9diua190r
--

CREATE TRIGGER trg_set_updated_at_notifications BEFORE UPDATE ON public.notifications FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: payroll trg_set_updated_at_payroll; Type: TRIGGER; Schema: public; Owner: uen9p9diua190r
--

CREATE TRIGGER trg_set_updated_at_payroll BEFORE UPDATE ON public.payroll FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: payroll_periods trg_set_updated_at_payroll_periods; Type: TRIGGER; Schema: public; Owner: uen9p9diua190r
--

CREATE TRIGGER trg_set_updated_at_payroll_periods BEFORE UPDATE ON public.payroll_periods FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: pdf_templates trg_set_updated_at_pdf_templates; Type: TRIGGER; Schema: public; Owner: uen9p9diua190r
--

CREATE TRIGGER trg_set_updated_at_pdf_templates BEFORE UPDATE ON public.pdf_templates FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: performance_reviews trg_set_updated_at_performance_reviews; Type: TRIGGER; Schema: public; Owner: uen9p9diua190r
--

CREATE TRIGGER trg_set_updated_at_performance_reviews BEFORE UPDATE ON public.performance_reviews FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: positions trg_set_updated_at_positions; Type: TRIGGER; Schema: public; Owner: uen9p9diua190r
--

CREATE TRIGGER trg_set_updated_at_positions BEFORE UPDATE ON public.positions FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: recruitment trg_set_updated_at_recruitment; Type: TRIGGER; Schema: public; Owner: uen9p9diua190r
--

CREATE TRIGGER trg_set_updated_at_recruitment BEFORE UPDATE ON public.recruitment FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: users trg_set_updated_at_users; Type: TRIGGER; Schema: public; Owner: uen9p9diua190r
--

CREATE TRIGGER trg_set_updated_at_users BEFORE UPDATE ON public.users FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: action_reversals fk_ar_audit; Type: FK CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.action_reversals
    ADD CONSTRAINT fk_ar_audit FOREIGN KEY (audit_log_id) REFERENCES public.audit_logs(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: action_reversals fk_ar_user; Type: FK CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.action_reversals
    ADD CONSTRAINT fk_ar_user FOREIGN KEY (reversed_by) REFERENCES public.users(id) ON UPDATE CASCADE ON DELETE RESTRICT;


--
-- Name: access_template_permissions fk_atp_tpl; Type: FK CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.access_template_permissions
    ADD CONSTRAINT fk_atp_tpl FOREIGN KEY (template_id) REFERENCES public.access_templates(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: attendance fk_attendance_employee; Type: FK CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.attendance
    ADD CONSTRAINT fk_attendance_employee FOREIGN KEY (employee_id) REFERENCES public.employees(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: audit_logs fk_audit_user; Type: FK CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES public.users(id) ON UPDATE CASCADE ON DELETE SET NULL;


--
-- Name: document_assignments fk_doc_assign_department; Type: FK CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.document_assignments
    ADD CONSTRAINT fk_doc_assign_department FOREIGN KEY (department_id) REFERENCES public.departments(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: document_assignments fk_doc_assign_document; Type: FK CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.document_assignments
    ADD CONSTRAINT fk_doc_assign_document FOREIGN KEY (document_id) REFERENCES public.documents(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: document_assignments fk_doc_assign_employee; Type: FK CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.document_assignments
    ADD CONSTRAINT fk_doc_assign_employee FOREIGN KEY (employee_id) REFERENCES public.employees(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: documents fk_documents_user; Type: FK CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.documents
    ADD CONSTRAINT fk_documents_user FOREIGN KEY (created_by) REFERENCES public.users(id) ON UPDATE CASCADE ON DELETE SET NULL;


--
-- Name: employees fk_employees_department; Type: FK CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.employees
    ADD CONSTRAINT fk_employees_department FOREIGN KEY (department_id) REFERENCES public.departments(id) ON UPDATE CASCADE ON DELETE SET NULL;


--
-- Name: employees fk_employees_position; Type: FK CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.employees
    ADD CONSTRAINT fk_employees_position FOREIGN KEY (position_id) REFERENCES public.positions(id) ON UPDATE CASCADE ON DELETE SET NULL;


--
-- Name: employees fk_employees_user; Type: FK CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.employees
    ADD CONSTRAINT fk_employees_user FOREIGN KEY (user_id) REFERENCES public.users(id) ON UPDATE CASCADE ON DELETE SET NULL;


--
-- Name: leave_requests fk_leave_employee; Type: FK CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.leave_requests
    ADD CONSTRAINT fk_leave_employee FOREIGN KEY (employee_id) REFERENCES public.employees(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: leave_request_actions fk_lra_leave; Type: FK CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.leave_request_actions
    ADD CONSTRAINT fk_lra_leave FOREIGN KEY (leave_request_id) REFERENCES public.leave_requests(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: leave_request_actions fk_lra_user; Type: FK CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.leave_request_actions
    ADD CONSTRAINT fk_lra_user FOREIGN KEY (acted_by) REFERENCES public.users(id) ON UPDATE CASCADE ON DELETE SET NULL;


--
-- Name: notifications fk_notifications_user; Type: FK CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES public.users(id) ON UPDATE CASCADE ON DELETE SET NULL;


--
-- Name: notification_reads fk_nr_notification; Type: FK CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.notification_reads
    ADD CONSTRAINT fk_nr_notification FOREIGN KEY (notification_id) REFERENCES public.notifications(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: notification_reads fk_nr_user; Type: FK CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.notification_reads
    ADD CONSTRAINT fk_nr_user FOREIGN KEY (user_id) REFERENCES public.users(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: payroll fk_payroll_employee; Type: FK CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.payroll
    ADD CONSTRAINT fk_payroll_employee FOREIGN KEY (employee_id) REFERENCES public.employees(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: payroll fk_payroll_period; Type: FK CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.payroll
    ADD CONSTRAINT fk_payroll_period FOREIGN KEY (period_id) REFERENCES public.payroll_periods(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: performance_reviews fk_perf_employee; Type: FK CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.performance_reviews
    ADD CONSTRAINT fk_perf_employee FOREIGN KEY (employee_id) REFERENCES public.employees(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: positions fk_positions_department; Type: FK CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.positions
    ADD CONSTRAINT fk_positions_department FOREIGN KEY (department_id) REFERENCES public.departments(id) ON UPDATE CASCADE ON DELETE SET NULL;


--
-- Name: recruitment_files fk_rec_files_app; Type: FK CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.recruitment_files
    ADD CONSTRAINT fk_rec_files_app FOREIGN KEY (recruitment_id) REFERENCES public.recruitment(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: recruitment_files fk_rec_files_user; Type: FK CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.recruitment_files
    ADD CONSTRAINT fk_rec_files_user FOREIGN KEY (uploaded_by) REFERENCES public.users(id) ON UPDATE CASCADE ON DELETE SET NULL;


--
-- Name: roles_meta_permissions fk_rmp_role; Type: FK CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.roles_meta_permissions
    ADD CONSTRAINT fk_rmp_role FOREIGN KEY (role_name) REFERENCES public.roles_meta(role_name) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: recruitment_template_files fk_rtf2_tpl; Type: FK CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.recruitment_template_files
    ADD CONSTRAINT fk_rtf2_tpl FOREIGN KEY (template_id) REFERENCES public.recruitment_templates(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: recruitment_template_fields fk_rtf_tpl; Type: FK CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.recruitment_template_fields
    ADD CONSTRAINT fk_rtf_tpl FOREIGN KEY (template_id) REFERENCES public.recruitment_templates(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: user_access_permissions fk_uap_user; Type: FK CONSTRAINT; Schema: public; Owner: uen9p9diua190r
--

ALTER TABLE ONLY public.user_access_permissions
    ADD CONSTRAINT fk_uap_user FOREIGN KEY (user_id) REFERENCES public.users(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: SCHEMA heroku_ext; Type: ACL; Schema: -; Owner: postgres
--

GRANT USAGE ON SCHEMA heroku_ext TO uen9p9diua190r WITH GRANT OPTION;


--
-- Name: SCHEMA public; Type: ACL; Schema: -; Owner: uen9p9diua190r
--

REVOKE USAGE ON SCHEMA public FROM PUBLIC;


--
-- Name: FUNCTION pg_stat_statements_reset(userid oid, dbid oid, queryid bigint, minmax_only boolean); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.pg_stat_statements_reset(userid oid, dbid oid, queryid bigint, minmax_only boolean) TO uen9p9diua190r;


--
-- PostgreSQL database dump complete
--

\unrestrict ONhiVNlLefqdZKjALIjTe6fC3qnem8VxDdKkgTBHUaxlxi9FJZC7JJSowl00Vy0


